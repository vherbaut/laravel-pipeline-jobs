<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Events;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

/**
 * Broadcast once per pipeline run at the terminal exit.
 *
 * Fires ONCE at the terminal exit of a pipeline run on EITHER branch, mirroring
 * the onComplete() pipeline-level callback semantic:
 *
 * - Success tail: after all steps complete and the onSuccess + onComplete
 *   callbacks fire, just before the executor returns the final context.
 * - StopImmediately failure tail: after the onFailure + onComplete
 *   callbacks fire and just before the StepExecutionFailed rethrow.
 * - StopAndCompensate failure tail: after the compensation chain has
 *   dispatched, after the onFailure + onComplete callbacks fire, and just
 *   before the StepExecutionFailed rethrow.
 * - SkipAndContinue: the pipeline reaches the success tail after recovering
 *   skipped steps, so PipelineCompleted fires on the success branch
 *   (symmetric with the onSuccess + onComplete callback semantic).
 *
 * Fires after the opt-in flag PipelineBuilder::dispatchEvents() is enabled.
 * When the flag is off this event is NEVER dispatched.
 *
 * Does NOT fire when the onComplete or onFailure callback itself throws
 * (that path raises StepExecutionFailed::forCallbackFailure() with complex
 * rethrow semantics; firing a terminal event on top would obscure the
 * original failure chain). Document rationale lives at
 * src/Execution/SyncExecutor.php catch block.
 *
 * Does NOT carry a Throwable: correlate with PipelineStepFailed via
 * $pipelineId for failure-path detail. Listeners that only care about
 * success vs. failure should register for PipelineStepFailed in addition.
 */
final class PipelineCompleted
{
    /**
     * Create a new PipelineCompleted event payload.
     *
     * @param string $pipelineId Unique identifier of the pipeline run, correlates with PipelineStepCompleted and PipelineStepFailed for the same run.
     * @param PipelineContext|null $context Final pipeline context at terminal exit, or null when the manifest has no context attached.
     * @return void
     */
    public function __construct(
        public readonly string $pipelineId,
        public readonly ?PipelineContext $context,
    ) {}
}
