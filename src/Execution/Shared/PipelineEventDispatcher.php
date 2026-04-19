<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Shared;

use Illuminate\Support\Facades\Event;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;

/**
 * Centralized opt-in dispatcher for pipeline lifecycle events.
 *
 * All three execution paths (SyncExecutor, PipelineStepJob for queued mode,
 * RecordingExecutor for Pipeline::fake()->recording()) call through this
 * helper so the opt-in flag check lives in exactly one place and cannot
 * diverge between modes. The helper's contract:
 *
 * - If $manifest->dispatchEvents is false, each method is a no-op. No event
 *   object is constructed, no Event::dispatch() is called, no listener
 *   resolution is triggered. This is the zero-overhead guarantee (AC #17):
 *   pipelines that did not call PipelineBuilder::dispatchEvents() pay
 *   literally nothing for the feature.
 * - If $manifest->dispatchEvents is true, the helper instantiates the
 *   appropriate event with the provided payload and forwards it to the
 *   Event facade. Listeners are resolved by Laravel's event dispatcher
 *   exactly as they are for user-land events.
 *
 * This helper does NOT gate CompensationFailed (that event is operational
 * alerting per NFR6 and fires unconditionally on compensation failure; see
 * src/Events/CompensationFailed.php:23-29). Pipeline lifecycle events are
 * an opt-in observability hook; compensation failure is a must-report
 * operational signal.
 */
final class PipelineEventDispatcher
{
    /**
     * Dispatch PipelineStepCompleted for a successful step completion when the opt-in flag is enabled.
     *
     * Called from the executor's success path AFTER afterEach hooks fire
     * and AFTER markStepCompleted() records the step on the manifest, so
     * listeners observe a fully-committed "step completed" state. The
     * $stepIndex follows the outer-index convention documented on the
     * PipelineStepCompleted event class (flat top-level, parallel wrapper
     * outer index, nested TOP-LEVEL outer index via nestedCursor[0],
     * branch group outer index).
     *
     * @param PipelineManifest $manifest The pipeline's manifest; its dispatchEvents flag drives the guard.
     * @param int $stepIndex Zero-based outer index of the step that just completed.
     * @param string $stepClass Fully qualified class name of the step that just completed.
     * @return void
     */
    public static function fireStepCompleted(PipelineManifest $manifest, int $stepIndex, string $stepClass): void
    {
        if (! $manifest->dispatchEvents) {
            return;
        }

        Event::dispatch(new PipelineStepCompleted(
            pipelineId: $manifest->pipelineId,
            context: $manifest->context,
            stepIndex: $stepIndex,
            stepClass: $stepClass,
        ));
    }

    /**
     * Dispatch PipelineStepFailed for a step failure when the opt-in flag is enabled.
     *
     * Called from the executor's catch block AFTER failure-field recording
     * on the manifest (failureException / failedStepClass / failedStepIndex)
     * and BEFORE onStepFailed per-step hooks fire. The "event before hook"
     * ordering is intentional: a throwing hook would replace the bubbling
     * exception, but listeners observing this event see the RAW step
     * failure every time.
     *
     * Fires under ALL FailStrategy branches (StopImmediately,
     * StopAndCompensate, SkipAndContinue). Selector failures in conditional
     * branches route through this helper with $stepClass set to the
     * ConditionalBranch&lt;&gt; label produced by StepExecutionFailed::forStep().
     *
     * @param PipelineManifest $manifest The pipeline's manifest; its dispatchEvents flag drives the guard.
     * @param int $stepIndex Zero-based outer index of the failing step.
     * @param string $stepClass Fully qualified class name of the failing step, or the ConditionalBranch&lt;&gt; label for selector failures.
     * @param Throwable $exception Throwable raised by the step's handle() method or the selector closure.
     * @return void
     */
    public static function fireStepFailed(PipelineManifest $manifest, int $stepIndex, string $stepClass, Throwable $exception): void
    {
        if (! $manifest->dispatchEvents) {
            return;
        }

        Event::dispatch(new PipelineStepFailed(
            pipelineId: $manifest->pipelineId,
            context: $manifest->context,
            stepIndex: $stepIndex,
            stepClass: $stepClass,
            exception: $exception,
        ));
    }

    /**
     * Dispatch PipelineCompleted once at terminal exit when the opt-in flag is enabled.
     *
     * Called once per pipeline run at the terminal exit on EITHER branch,
     * mirroring the onComplete() callback. On the success tail the helper
     * is called AFTER the onComplete pipeline-level callback fires; on the
     * StopImmediately and StopAndCompensate failure tails the helper is
     * called AFTER the onFailure + onComplete callbacks fire and just
     * before the terminal StepExecutionFailed rethrow. Under
     * SkipAndContinue the pipeline reaches the success tail (skipped steps
     * are converted into continuations), so PipelineCompleted fires on the
     * success branch.
     *
     * Does NOT fire when the onComplete or onFailure callback itself throws
     * (the callback-failure path has complex rethrow semantics and firing a
     * terminal event on top would obscure the original failure chain).
     *
     * @param PipelineManifest $manifest The pipeline's manifest; its dispatchEvents flag drives the guard.
     * @return void
     */
    public static function fireCompleted(PipelineManifest $manifest): void
    {
        if (! $manifest->dispatchEvents) {
            return;
        }

        Event::dispatch(new PipelineCompleted(
            pipelineId: $manifest->pipelineId,
            context: $manifest->context,
        ));
    }
}
