<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Exceptions;

/**
 * Thrown when a pipeline step fails during execution.
 *
 * Wraps the original exception with pipeline context information
 * (pipeline ID, step index, step class) to aid debugging.
 */
class StepExecutionFailed extends PipelineException
{
    /**
     * Create an exception for a step that failed during pipeline execution.
     *
     * @param string $pipelineId The unique identifier of the pipeline run.
     * @param int $stepIndex The zero-based index of the step that failed.
     * @param string $stepClass The fully qualified class name of the failing step.
     * @param \Throwable $previous The original exception thrown by the step.
     */
    public static function forStep(string $pipelineId, int $stepIndex, string $stepClass, \Throwable $previous): self
    {
        return new self(
            message: "Pipeline [{$pipelineId}] failed at step {$stepIndex} ({$stepClass}): {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
