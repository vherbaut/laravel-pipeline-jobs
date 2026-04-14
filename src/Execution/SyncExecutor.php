<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

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
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Synchronous pipeline executor that runs all steps sequentially
 * in the current process.
 *
 * Each step receives the same PipelineManifest (and thus the same
 * PipelineContext instance), so mutations are immediately visible
 * to subsequent steps. Execution stops on first failure.
 */
final class SyncExecutor implements PipelineExecutor
{
    /**
     * Execute all steps defined in the pipeline synchronously.
     *
     * Iterates through each step in order, instantiating the job via the
     * container, injecting the manifest, and calling handle() with DI
     * resolution. On step failure the behavior depends on the manifest's
     * failStrategy:
     *
     * - StopImmediately: rethrows as StepExecutionFailed (default).
     * - StopAndCompensate: runs the compensation chain in reverse order over
     *   completed steps, then rethrows as StepExecutionFailed.
     * - SkipAndContinue: records the failure on the manifest, logs a warning,
     *   advances past the failed step, and resumes with the next step. The
     *   pipeline does not throw. Any subsequent successful step clears the
     *   recorded failure fields; a later failure overwrites them.
     *
     * Per-step lifecycle hooks (Story 6.1) fire at three points:
     *
     * - beforeEach: fires after the skip check and manifest injection,
     *   immediately before the step's handle() is called. Skipped steps
     *   (when()/unless() returning the exclusion branch) do NOT trigger
     *   beforeEach.
     * - afterEach: fires after handle() returns successfully, BEFORE
     *   markStepCompleted() and advanceStep() run. A throwing afterEach is
     *   caught by the surrounding try/catch and routed through the standard
     *   failure path, so the step is NOT marked completed.
     * - onStepFailed: fires inside the catch block after failure-field
     *   recording on the manifest and BEFORE FailStrategy branching. A
     *   throwing onStepFailed propagates and bypasses the FailStrategy
     *   branching for the current failure (no compensation, no skip).
     *
     * Hook exceptions propagate: beforeEach/afterEach throws route through
     * the standard step-failure path (onStepFailed fires, FailStrategy
     * applies); onStepFailed throws bypass the FailStrategy for the current
     * failure.
     *
     * @param PipelineDefinition $definition The immutable pipeline description containing steps and configuration.
     * @param PipelineManifest $manifest The mutable execution state carrying context and step progress.
     * @return PipelineContext|null The final pipeline context after execution, or null if the pipeline has no context.
     *
     * @throws StepExecutionFailed When a step throws under StopImmediately or StopAndCompensate.
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

                // AC #6: a successful step under SkipAndContinue clears any
                // failure recorded by a previously skipped step. No-op under
                // StopImmediately / StopAndCompensate because those paths
                // never set the fields except immediately before rethrowing.
                $manifest->failureException = null;
                $manifest->failedStepClass = null;
                $manifest->failedStepIndex = null;
            } catch (Throwable $exception) {
                // Last-failure-wins: subsequent failures overwrite the recorded fields.
                $manifest->failureException = $exception;
                $manifest->failedStepClass = $stepClass;
                $manifest->failedStepIndex = $stepIndex;

                // Story 6.1 AC #3/#7/#8/#9: onStepFailed fires BEFORE FailStrategy
                // branching. A throwing onStepFailed bypasses the FailStrategy
                // branching for THIS failure; the hook's exception replaces the
                // original and is wrapped as StepExecutionFailed in sync mode.
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

                if ($manifest->failStrategy === FailStrategy::SkipAndContinue) {
                    Log::warning('Pipeline step skipped under SkipAndContinue', [
                        'pipelineId' => $manifest->pipelineId,
                        'stepClass' => $stepClass,
                        'stepIndex' => $stepIndex,
                        'exception' => $exception->getMessage(),
                    ]);

                    // Symmetric with the queued path (PipelineStepJob): drop
                    // the Throwable reference after logging so downstream
                    // observers do not see a stale live exception on the
                    // manifest (DD #7 belt-and-suspenders).
                    $manifest->failureException = null;

                    $manifest->advanceStep();

                    continue;
                }

                if ($manifest->failStrategy === FailStrategy::StopAndCompensate) {
                    $this->runCompensationChain($manifest);
                }

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
     * Returns false when no condition is registered for the index. Otherwise
     * unwraps the SerializableClosure, evaluates it against the current
     * context, and applies the `negated` flag. A throwing closure propagates
     * so the surrounding catch block converts it to StepExecutionFailed.
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
     * Unwraps each SerializableClosure via getClosure() and calls it with
     * either ($step, $context) for beforeEach/afterEach hooks (exception is
     * null) or ($step, $context, $exception) for onStepFailed hooks.
     * Hook exceptions propagate on first throw: the loop aborts and
     * subsequent hooks in the array are NOT invoked. This matches the
     * Story 6.1 no-silent-swallow contract (AC #6, AC #7).
     *
     * Zero-overhead when unused: if $hooks is an empty array, the foreach
     * body never executes and no SerializableClosure unwrap occurs.
     *
     * Duplicated across SyncExecutor, PipelineStepJob, and RecordingExecutor
     * per Story 5.2 Design Decision #2 (three-site duplication preferred
     * over a shared helper for readability).
     *
     * @param array<int, SerializableClosure> $hooks Ordered list of wrapped hook closures to invoke.
     * @param StepDefinition $step Minimal snapshot of the currently executing step (jobClass only).
     * @param PipelineContext|null $context The live pipeline context, or null when no context was sent.
     * @param Throwable|null $exception The caught throwable when firing onStepFailed hooks; null for beforeEach/afterEach.
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
     * Run compensation jobs for every completed step in reverse order.
     *
     * Only invoked when $manifest->failStrategy === FailStrategy::StopAndCompensate.
     * Reads the ordered list of completed steps from $manifest->completedSteps,
     * reverses it, and for each completed step whose class has a compensation
     * mapping in $manifest->compensationMapping, resolves the compensation via
     * the container and invokes it through the CompensableJob-or-trait bridge:
     *
     * - If the compensation implements CompensableJob, calls compensate($context).
     * - Otherwise, injects the manifest into a pipelineManifest property when
     *   present, then calls handle() via the container (Story 3.3 pattern).
     *
     * Compensation is best-effort: a throwable from one compensation is silently
     * swallowed so the chain continues with the next entry. Logging and event
     * emission on compensation failure are deferred to Story 5.3 (NFR6).
     *
     * @param PipelineManifest $manifest The manifest carrying completedSteps, compensationMapping, and context.
     *
     * @return void
     */
    private function runCompensationChain(PipelineManifest $manifest): void
    {
        if ($manifest->compensationMapping === []) {
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

                    continue;
                }

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                app()->call([$job, 'handle']);
            } catch (Throwable $compensationException) {
                $this->reportCompensationFailure(
                    $manifest,
                    $compensationClass,
                    $manifest->failureException,
                    $compensationException,
                );
                // Best-effort: compensation failures do not abort the chain.
            }
        }
    }

    /**
     * Invoke a CompensableJob's compensate() method, passing the FailureContext when the implementation accepts it.
     *
     * The CompensableJob interface declares a single-argument signature
     * (PipelineContext only); implementations may widen to two arguments to
     * opt into the Story 5.3 failure-context feature. Reflection is used to
     * detect both the parameter count and the second parameter's declared
     * type so the executor only passes the extra argument when the
     * implementation actually accepts a FailureContext. Signatures that
     * require more than two parameters are rejected at invocation time.
     *
     * @param CompensableJob $job The compensation job instance resolved from the container.
     * @param PipelineContext|null $context The pipeline context present at the failure point (may be null for context-less pipelines).
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
     * Returns false for single-parameter signatures and for two-parameter
     * signatures whose second parameter type cannot be satisfied by a
     * FailureContext instance (incompatible class type, scalar type, or
     * intersection type that includes interfaces FailureContext does not
     * implement). Untyped, mixed, object, FailureContext, or compatible
     * supertype parameters all return true.
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

            return false;
        }

        // ReflectionIntersectionType: FailureContext is final and implements
        // no interfaces, so no intersection of types can be satisfied by it.
        return false;
    }

    /**
     * Emit the NFR6 observability pair for a compensation failure.
     *
     * Writes a structured `Log::error('Pipeline compensation failed', [...])`
     * line and dispatches a `CompensationFailed` event carrying the pipeline
     * identifier, the compensation class, the failing step class, and both
     * exceptions. Invoked from inside `runCompensationChain()` per-iteration
     * catch blocks; does not abort the chain (sync best-effort semantics).
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
