<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
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
     * resolution. Stops on first failure and wraps the exception.
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
}
