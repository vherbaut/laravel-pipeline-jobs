<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineExecutor;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Test executor that runs all steps synchronously while capturing
 * per-step context snapshots for assertion.
 *
 * Follows the same execution flow as SyncExecutor: iterates step classes
 * in order, instantiates via the container, injects the manifest, and
 * calls handle(). After each step completes, a deep clone of the current
 * context is stored in execution order.
 *
 * This executor lives in the Testing namespace and is only used when
 * Pipeline::fake()->recording() mode is active.
 */
final class RecordingExecutor implements PipelineExecutor
{
    /** @var array<int, string> */
    private array $executedSteps = [];

    /** @var array<int, PipelineContext> */
    private array $contextSnapshots = [];

    private bool $compensationTriggered = false;

    /** @var array<int, string> */
    private array $compensationSteps = [];

    /**
     * Execute all steps synchronously, capturing context snapshots after each step.
     *
     * Mirrors SyncExecutor::execute() behaviorally: fires the three
     * Story 6.1 lifecycle hooks (beforeEach before handle(), afterEach
     * after successful handle() and before markStepCompleted(),
     * onStepFailed inside catch before compensation) so tests using
     * Pipeline::fake()->recording() observe the same hook contract as
     * production. Hook side effects precede the snapshot capture so a
     * recorded context reflects the post-hook state.
     *
     * @param PipelineDefinition $definition The immutable pipeline description containing steps and configuration.
     * @param PipelineManifest $manifest The mutable execution state carrying context and step progress.
     * @return PipelineContext|null The final pipeline context after execution, or null if no context was set.
     *
     * @throws StepExecutionFailed When any step throws an exception during execution.
     */
    public function execute(PipelineDefinition $definition, PipelineManifest $manifest): ?PipelineContext
    {
        foreach ($manifest->stepClasses as $stepIndex => $stepClass) {
            try {
                if ($this->shouldSkipStep($manifest, $stepIndex)) {
                    $manifest->advanceStep();

                    continue;
                }

                $job = app()->make($stepClass);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                $this->fireHooks(
                    $manifest->beforeEachHooks,
                    StepDefinition::fromJobClass($stepClass),
                    $manifest->context,
                );

                app()->call([$job, 'handle']);

                $this->fireHooks(
                    $manifest->afterEachHooks,
                    StepDefinition::fromJobClass($stepClass),
                    $manifest->context,
                );

                $manifest->markStepCompleted($stepClass);
                $manifest->advanceStep();

                $this->executedSteps[] = $stepClass;

                if ($manifest->context !== null) {
                    $this->contextSnapshots[] = unserialize(serialize($manifest->context));
                }
            } catch (Throwable $exception) {
                $manifest->failureException = $exception;
                $manifest->failedStepClass = $stepClass;
                $manifest->failedStepIndex = $stepIndex;

                // Story 6.1 AC #7: a throwing onStepFailed bypasses the
                // FailStrategy branching (no compensation) and is wrapped
                // as StepExecutionFailed to mirror SyncExecutor (AC #10
                // recording/sync parity).
                try {
                    $this->fireHooks(
                        $manifest->onStepFailedHooks,
                        StepDefinition::fromJobClass($stepClass),
                        $manifest->context,
                        $exception,
                    );
                } catch (Throwable $hookException) {
                    throw StepExecutionFailed::forStep(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $stepClass,
                        $hookException,
                    );
                }

                $this->runCompensation($manifest);

                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $manifest->currentStepIndex,
                    $stepClass,
                    $exception,
                );
            }
        }

        return $manifest->context;
    }

    /**
     * Decide whether the step at the given index should be skipped based on its condition entry.
     *
     * Mirrors SyncExecutor::shouldSkipStep(). Must be called from within
     * the execute() try block so a throwing closure propagates as
     * StepExecutionFailed and triggers compensation, matching the
     * production executor's behaviour.
     *
     * @param PipelineManifest $manifest The manifest carrying stepConditions and context.
     * @param int $stepIndex The zero-based index of the step being evaluated.
     *
     * @return bool True when the step must be skipped, false when it should run.
     */
    private function shouldSkipStep(PipelineManifest $manifest, int $stepIndex): bool
    {
        $entry = $manifest->stepConditions[$stepIndex] ?? null;

        if ($entry === null) {
            return false;
        }

        $closure = $entry['closure']->getClosure();
        $result = (bool) $closure($manifest->context);
        $shouldRun = $entry['negated'] ? ! $result : $result;

        return ! $shouldRun;
    }

    /**
     * Invoke a hook array in registration order with the appropriate arguments.
     *
     * Mirrors SyncExecutor::fireHooks() and PipelineStepJob::fireHooks() to
     * preserve recording-mode parity with production behavior (Story 6.1
     * AC #10). Hook exceptions propagate; the loop aborts on first throw
     * (Story 6.1 AC #7).
     *
     * @param array<int, SerializableClosure> $hooks Ordered list of wrapped hook closures.
     * @param StepDefinition $step Minimal snapshot of the currently executing step.
     * @param PipelineContext|null $context The live pipeline context, or null when no context was sent.
     * @param Throwable|null $exception The caught throwable for onStepFailed hooks; null for beforeEach/afterEach.
     * @return void
     */
    private function fireHooks(array $hooks, StepDefinition $step, ?PipelineContext $context, ?Throwable $exception = null): void
    {
        foreach ($hooks as $hook) {
            $closure = $hook->getClosure();

            if ($exception === null) {
                $closure($step, $context);

                continue;
            }

            $closure($step, $context, $exception);
        }
    }

    /**
     * Get the ordered list of step class names that completed execution.
     *
     * @return array<int, string> Fully qualified class names in execution order.
     */
    public function executedSteps(): array
    {
        return $this->executedSteps;
    }

    /**
     * Get the per-step context snapshots captured during execution.
     *
     * Each snapshot is a deep clone of the context as it was immediately
     * after the corresponding step completed, stored in execution order.
     *
     * @return array<int, PipelineContext> Snapshots in execution order.
     */
    public function contextSnapshots(): array
    {
        return $this->contextSnapshots;
    }

    /**
     * Check whether compensation was triggered during execution.
     *
     * @return bool True if at least one compensation job was executed.
     */
    public function compensationTriggered(): bool
    {
        return $this->compensationTriggered;
    }

    /**
     * Get the ordered list of compensation job class names that were executed.
     *
     * @return array<int, string> Compensation classes in execution order (reverse of completed steps).
     */
    public function compensationSteps(): array
    {
        return $this->compensationSteps;
    }

    /**
     * Run compensation jobs for completed steps in reverse order.
     *
     * Guarded on the manifest's failStrategy: only fires when strategy is
     * FailStrategy::StopAndCompensate AND a compensation mapping exists.
     * StopImmediately and SkipAndContinue both skip compensation entirely
     * (SkipAndContinue step-level handling is deferred to Story 5.3).
     *
     * Uses the CompensableJob-or-trait bridge: instances implementing
     * CompensableJob receive a compensate($context) call; otherwise the
     * legacy Story 3.3 pattern fires (reflection-injected manifest plus
     * app()->call([$job, 'handle'])). Per-compensation failures are
     * silently swallowed so the chain continues.
     *
     * @param PipelineManifest $manifest The pipeline manifest carrying completed steps, compensation mapping, context, and strategy.
     *
     * @return void
     */
    private function runCompensation(PipelineManifest $manifest): void
    {
        if ($manifest->compensationMapping === [] || $manifest->failStrategy !== FailStrategy::StopAndCompensate) {
            return;
        }

        $reversedCompleted = array_reverse($manifest->completedSteps);
        $failureContext = FailureContext::fromManifest($manifest);

        foreach ($reversedCompleted as $completedStep) {
            if (! isset($manifest->compensationMapping[$completedStep])) {
                continue;
            }

            $compensationClass = $manifest->compensationMapping[$completedStep];

            try {
                $job = app()->make($compensationClass);

                if ($job instanceof CompensableJob) {
                    $this->invokeCompensate($job, $manifest->context, $failureContext);
                } else {
                    if (property_exists($job, 'pipelineManifest')) {
                        $property = new ReflectionProperty($job, 'pipelineManifest');
                        $property->setValue($job, $manifest);
                    }

                    app()->call([$job, 'handle']);
                }

                $this->compensationSteps[] = $compensationClass;
            } catch (Throwable $compensationException) {
                $this->compensationSteps[] = $compensationClass;
                $this->reportCompensationFailure(
                    $manifest,
                    $compensationClass,
                    $manifest->failureException,
                    $compensationException,
                );
            }
        }

        if ($this->compensationSteps !== []) {
            $this->compensationTriggered = true;
        }
    }

    /**
     * Invoke a CompensableJob's compensate() method, passing the FailureContext when the implementation accepts it.
     *
     * Mirrors SyncExecutor::invokeCompensate(): reflection-based dispatch
     * that only passes the extra FailureContext argument when the
     * implementation accepts a FailureContext-compatible second parameter.
     * Rejects signatures that require more than two parameters. Duplicated
     * deliberately across the three compensation bridges per Story 5.2 Design
     * Decision #2 (duplication over premature helper extraction).
     *
     * @param CompensableJob $job The compensation job instance resolved from the container.
     * @param PipelineContext|null $context The pipeline context present at the failure point.
     * @param FailureContext|null $failure The failure-context snapshot, or null when no failure was recorded on the manifest.
     * @return void
     *
     * @throws LogicException When the compensate() signature declares more than two required parameters.
     */
    private function invokeCompensate(CompensableJob $job, ?PipelineContext $context, ?FailureContext $failure): void
    {
        $method = new ReflectionMethod($job, 'compensate');

        if ($method->getNumberOfRequiredParameters() > 2) {
            throw new LogicException(sprintf(
                'Compensation class [%s] declares compensate() with more than two required parameters; the executor only provides $context and $failure.',
                $job::class,
            ));
        }

        $args = self::compensateAcceptsFailureContext($method) ? [$context, $failure] : [$context];
        $method->invoke($job, ...$args);
    }

    /**
     * Decide whether a compensate() reflection method accepts a FailureContext as its second argument.
     *
     * @param ReflectionMethod $method The reflected compensate() method.
     * @return bool True when a FailureContext instance can be safely passed as the second argument.
     */
    private static function compensateAcceptsFailureContext(ReflectionMethod $method): bool
    {
        if ($method->getNumberOfParameters() < 2) {
            return false;
        }

        $type = $method->getParameters()[1]->getType();

        if ($type === null) {
            return true;
        }

        return self::typeAcceptsFailureContext($type);
    }

    /**
     * Recursive type-compatibility probe for compensateAcceptsFailureContext().
     *
     * @param ReflectionType $type A reflected parameter type (named, union, or intersection).
     * @return bool True when a FailureContext instance satisfies the declared type.
     */
    private static function typeAcceptsFailureContext(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return $type->getName() === 'mixed' || $type->getName() === 'object';
            }

            $name = $type->getName();

            return $name === FailureContext::class || is_subclass_of(FailureContext::class, $name);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if (self::typeAcceptsFailureContext($inner)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Emit the NFR6 observability pair for a compensation failure.
     *
     * Mirrors SyncExecutor::reportCompensationFailure() so tests that use
     * Pipeline::fake()->recording() observe the same log + event signal as
     * production. Invoked from inside the per-compensation catch block in
     * runCompensation(); does not abort the chain (sync best-effort).
     *
     * @param PipelineManifest $manifest The manifest carrying pipelineId and failedStepClass.
     * @param string $compensationClass Fully qualified class name of the compensation job that threw.
     * @param Throwable|null $originalException Throwable raised by the failing step, or null when no failure was recorded.
     * @param Throwable $compensationException Throwable raised by the compensation job itself.
     * @return void
     */
    private function reportCompensationFailure(
        PipelineManifest $manifest,
        string $compensationClass,
        ?Throwable $originalException,
        Throwable $compensationException,
    ): void {
        Log::error('Pipeline compensation failed', [
            'pipelineId' => $manifest->pipelineId,
            'compensationClass' => $compensationClass,
            'failedStepClass' => $manifest->failedStepClass,
            'compensationException' => $compensationException->getMessage(),
        ]);

        Event::dispatch(new CompensationFailedEvent(
            pipelineId: $manifest->pipelineId,
            compensationClass: $compensationClass,
            failedStepClass: $manifest->failedStepClass,
            originalException: $originalException,
            compensationException: $compensationException,
        ));
    }
}
