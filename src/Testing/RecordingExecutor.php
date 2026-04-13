<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineExecutor;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;

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

                app()->call([$job, 'handle']);

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

        foreach ($reversedCompleted as $completedStep) {
            if (! isset($manifest->compensationMapping[$completedStep])) {
                continue;
            }

            $compensationClass = $manifest->compensationMapping[$completedStep];

            try {
                $job = app()->make($compensationClass);

                if ($job instanceof CompensableJob) {
                    $job->compensate($manifest->context);
                } else {
                    if (property_exists($job, 'pipelineManifest')) {
                        $property = new ReflectionProperty($job, 'pipelineManifest');
                        $property->setValue($job, $manifest);
                    }

                    app()->call([$job, 'handle']);
                }

                $this->compensationSteps[] = $compensationClass;
            } catch (Throwable) {
                $this->compensationSteps[] = $compensationClass;
            }
        }

        if ($this->compensationSteps !== []) {
            $this->compensationTriggered = true;
        }
    }
}
