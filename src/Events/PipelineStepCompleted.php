<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Events;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

/**
 * Broadcast after a flat pipeline step's handle() returns successfully.
 *
 * Dispatched from three places when and only when the pipeline's
 * PipelineBuilder::dispatchEvents() opt-in flag is enabled:
 *
 * - SyncExecutor::execute() at step-completion lifecycle point (after
 *   afterEach hooks fire and after markStepCompleted() flips the manifest).
 * - PipelineStepJob::handle() queued-mode mirror (after afterEach hooks fire
 *   and before advanceAndContinueOrTerminate() hops to the next worker).
 * - RecordingExecutor when Pipeline::fake()->recording() replays execution
 *   through the testing observer.
 *
 * Zero-overhead guarantee: when the opt-in flag is off, this event is NEVER
 * allocated or dispatched (the centralized PipelineEventDispatcher helper
 * guards every call site with a flag check before constructing the event).
 *
 * Index semantics:
 *
 * - Flat top-level step: $stepIndex matches the step's position in the
 *   user-authored pipeline definition array.
 * - Parallel sub-step: $stepIndex is the OUTER group index (the parallel
 *   wrapper's position), not the sub-step's internal index; $stepClass
 *   disambiguates the specific sub-step that completed.
 * - Nested inner step: $stepIndex is the TOP-LEVEL outer group index via
 *   $manifest->nestedCursor[0] so listeners correlate against the
 *   user-visible outer position.
 * - Branch inner step: $stepIndex is the branch group's outer index; only
 *   the selected branch's inner steps fire this event.
 *
 * A step that was skipped via when()/unless() runtime conditions does NOT
 * fire this event. A step that was SkipAndContinue-recovered fires only
 * PipelineStepFailed; a step that succeeded on retry after prior failures
 * fires this event once (on the successful attempt).
 */
final class PipelineStepCompleted
{
    /**
     * Create a new PipelineStepCompleted event payload.
     *
     * @param string $pipelineId Unique identifier of the pipeline run, correlates with PipelineStepFailed and PipelineCompleted payloads for the same run.
     * @param PipelineContext|null $context Current pipeline context at the moment of completion, or null when the pipeline runs without an initial context (rare, typically only in parallel sub-runs that merge later).
     * @param int $stepIndex Zero-based outer index of the step in the user-authored pipeline definition; see class-level docblock for parallel/nested/branch semantics.
     * @param string $stepClass Fully qualified class name of the step that just completed.
     * @return void
     */
    public function __construct(
        public readonly string $pipelineId,
        public readonly ?PipelineContext $context,
        public readonly int $stepIndex,
        public readonly string $stepClass,
    ) {}
}
