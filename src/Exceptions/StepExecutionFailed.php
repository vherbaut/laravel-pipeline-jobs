<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Exceptions;

/**
 * Thrown when a pipeline step fails during execution.
 *
 * Wraps the original exception with pipeline context information
 * (pipeline ID, step index, step class) to aid debugging.
 *
 * Two flavours of failure are represented:
 *
 * - Step-level failure via forStep(): the step's handle() (or a per-step hook)
 *   threw. $previous carries the thrown Throwable; $originalStepException is null.
 * - Callback-level failure via forCallbackFailure(): a pipeline-level callback
 *   (onSuccess, onFailure, or onComplete) threw AFTER a step had already failed.
 *   $previous carries the callback exception (which replaces the step
 *   exception as the bubbling Throwable), and $originalStepException preserves the
 *   original step Throwable so operators retain full observability.
 */
class StepExecutionFailed extends PipelineException
{
    /**
     * The original step exception captured when a pipeline-level callback
     * throws post-step-failure, or null for step-level failures produced by
     * forStep().
     *
     * Populated only by forCallbackFailure(). Readers use this slot to observe
     * the step failure that precipitated the callback invocation; the callback
     * exception itself is available via getPrevious().
     *
     * @var \Throwable|null
     */
    public readonly ?\Throwable $originalStepException;

    /**
     * Construct the exception with an optional $previous chain and an
     * optional preserved original step exception.
     *
     * @param string $message The formatted error message describing which pipeline/step failed.
     * @param \Throwable|null $previous The immediate cause (step exception in forStep, callback exception in forCallbackFailure).
     * @param \Throwable|null $originalStepException The original step exception preserved when a pipeline-level callback replaces it; null for step-level failures.
     * @return void
     */
    public function __construct(
        string $message = '',
        ?\Throwable $previous = null,
        ?\Throwable $originalStepException = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->originalStepException = $originalStepException;
    }

    /**
     * Create an exception for a step that failed during pipeline execution.
     *
     * @param string $pipelineId The unique identifier of the pipeline run.
     * @param int $stepIndex The zero-based index of the step that failed.
     * @param string $stepClass The fully qualified class name of the failing step.
     * @param \Throwable $previous The original exception thrown by the step.
     * @return self
     */
    public static function forStep(string $pipelineId, int $stepIndex, string $stepClass, \Throwable $previous): self
    {
        return new self(
            message: "Pipeline [{$pipelineId}] failed at step {$stepIndex} ({$stepClass}): {$previous->getMessage()}",
            previous: $previous,
        );
    }

    /**
     * Create an exception for a pipeline-level callback (onFailure / onComplete)
     * that threw after a step had already failed.
     *
     * The callback exception REPLACES the original step exception as the one
     * that bubbles out of the executor. The
     * original step exception is preserved on the $originalStepException
     * slot so observability is retained without duplicating the Throwable
     * in the $previous chain.
     *
     * @param string $pipelineId The unique identifier of the pipeline run.
     * @param int $stepIndex The zero-based index of the step whose terminal branch triggered the callback.
     * @param string $stepClass The fully qualified class name of the failing step.
     * @param \Throwable $callbackException The throwable raised by the pipeline-level callback; becomes getPrevious().
     * @param \Throwable $originalStepException The original step throwable that preceded the callback firing; stored on $originalStepException.
     * @return self
     */
    public static function forCallbackFailure(
        string $pipelineId,
        int $stepIndex,
        string $stepClass,
        \Throwable $callbackException,
        \Throwable $originalStepException,
    ): self {
        return new self(
            message: "Pipeline [{$pipelineId}] callback failed at step {$stepIndex} ({$stepClass}): {$callbackException->getMessage()}",
            previous: $callbackException,
            originalStepException: $originalStepException,
        );
    }
}
