<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Events;

use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

/**
 * Broadcast when a flat pipeline step's handle() throws.
 *
 * Dispatched from three places when and only when the pipeline's
 * PipelineBuilder::dispatchEvents() opt-in flag is enabled:
 *
 * - SyncExecutor::execute() catch block immediately after failure fields
 *   (failureException, failedStepClass, failedStepIndex) are recorded on
 *   the manifest and BEFORE onStepFailed per-step hooks fire. Listeners
 *   therefore observe the RAW step failure even when a throwing hook would
 *   later replace the bubbling exception.
 * - PipelineStepJob::handle() queued-mode mirror (same ordering: after
 *   failure-field recording, before onStepFailed hooks).
 * - RecordingExecutor for Pipeline::fake()->recording() symmetry.
 *
 * Fires under ALL FailStrategy branches: StopImmediately, StopAndCompensate,
 * and SkipAndContinue. Under SkipAndContinue the failed step does NOT also
 * fire PipelineStepCompleted (the two events are mutually exclusive per
 * step attempt).
 *
 * Selector failures in conditional branches fire this event with
 * $stepClass set to the ConditionalBranch&lt;&gt; label produced by
 * StepExecutionFailed::forStep() (matches the label at
 * src/Execution/SyncExecutor.php:1086) and $exception wrapping the selector
 * failure reason (throw, non-string return, unknown key).
 *
 * Queued-listener caveat (Throwable serialization): listeners registered
 * with ShouldQueue receive a payload containing a live Throwable. Laravel's
 * queued-listener serializer may fail or sanitize the Throwable on its way
 * to the queue. Queued listeners should extract the essentials (class name,
 * message) from $exception inside a non-queued listener that forwards to
 * the queued work, rather than rely on the Throwable surviving queue
 * transport.
 */
final class PipelineStepFailed
{
    /**
     * Create a new PipelineStepFailed event payload.
     *
     * @param string $pipelineId Unique identifier of the pipeline run, correlates with PipelineStepCompleted and PipelineCompleted payloads for the same run.
     * @param PipelineContext|null $context Current pipeline context at the moment of failure, or null when the manifest has no context attached.
     * @param int $stepIndex Zero-based outer index of the failing step in the user-authored pipeline definition; same semantics as PipelineStepCompleted for parallel/nested/branch.
     * @param string $stepClass Fully qualified class name of the failing step, or the ConditionalBranch&lt;&gt; label for selector failures.
     * @param Throwable $exception Throwable raised by the step's handle() method or the selector closure; always non-null (the event fires only on a real failure).
     * @return void
     */
    public function __construct(
        public readonly string $pipelineId,
        public readonly ?PipelineContext $context,
        public readonly int $stepIndex,
        public readonly string $stepClass,
        public readonly Throwable $exception,
    ) {}
}
