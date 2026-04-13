<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;

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
     * resolution. Stops on first failure and wraps the exception. When the
     * manifest's failStrategy is StopAndCompensate, the compensation jobs
     * for every completed step are invoked in reverse order before the
     * wrapped StepExecutionFailed is rethrown.
     *
     * @param PipelineDefinition $definition The immutable pipeline description containing steps and configuration.
     * @param PipelineManifest $manifest The mutable execution state carrying context and step progress.
     * @return PipelineContext|null The final pipeline context after execution, or null if the pipeline has no context.
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
            } catch (Throwable $exception) {
                $manifest->failureException = $exception;
                $manifest->failedStepClass = $stepClass;
                $manifest->failedStepIndex = $stepIndex;

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

        foreach ($reversedCompleted as $completedStep) {
            if (! isset($manifest->compensationMapping[$completedStep])) {
                continue;
            }

            $compensationClass = $manifest->compensationMapping[$completedStep];

            try {
                $job = app()->make($compensationClass);

                if ($job instanceof CompensableJob) {
                    $job->compensate($manifest->context);

                    continue;
                }

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                app()->call([$job, 'handle']);
            } catch (Throwable) {
                // Best-effort: compensation failures do not abort the chain.
            }
        }
    }
}
