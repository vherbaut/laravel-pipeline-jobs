<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepConditionEvaluator;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvoker;
use Vherbaut\LaravelPipelineJobs\Execution\Sync\SyncCompensationRunner;
use Vherbaut\LaravelPipelineJobs\Execution\Sync\SyncConditionalBranchRunner;
use Vherbaut\LaravelPipelineJobs\Execution\Sync\SyncNestedPipelineRunner;
use Vherbaut\LaravelPipelineJobs\Execution\Sync\SyncParallelGroupRunner;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Synchronous pipeline executor that runs all steps sequentially
 * in the current process.
 *
 * Each step receives the same PipelineManifest (and thus the same
 * PipelineContext instance), so mutations are immediately visible
 * to subsequent steps. Execution stops on first failure.
 *
 * Per-step queue, connection, and sync configuration (StepDefinition::$queue,
 * ::$connection, ::$sync) is INERT in synchronous mode. Every step runs
 * inline via `app()->call([$job, 'handle'])` regardless of the declared
 * queue routing. The `stepConfigs` field on the manifest is populated for
 * parity with queued-mode manifests but is never consulted by this
 * executor. Consumers that need queue-routed dispatch must call
 * `->shouldBeQueued()` on the builder so QueuedExecutor and PipelineStepJob
 * handle routing instead.
 *
 * Per-step `retry` and `backoff` are ACTIVE in synchronous mode: the retry
 * loop runs in-process via `invokeStepWithRetry()` with `sleep($backoff)`
 * between attempts. Per-step `timeout` is INERT in synchronous mode because
 * Laravel's native timeout mechanism relies on `pcntl_alarm` inside the
 * queue worker, which is not part of the synchronous `run()` flow.
 * Consumers needing a per-step timeout guarantee must declare
 * `->shouldBeQueued()` so the wrapper's `$timeout` property is honored.
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
     * Pipeline-level lifecycle callbacks (Story 6.2) fire at two points:
     *
     * - On terminal success (all steps ran, pipeline returns): onSuccess
     *   fires first, then onComplete. Under FailStrategy::SkipAndContinue the
     *   pipeline reaches the success tail and fires both callbacks regardless
     *   of whether intermediate steps failed (AC #10).
     * - On terminal failure (StopImmediately rethrow, or StopAndCompensate
     *   post-compensation rethrow): onFailure fires first, then onComplete.
     *   Under SkipAndContinue this branch is unreachable.
     *
     * Callback throws propagate: onSuccess/onComplete throws bubble out
     * unwrapped; a throwing onFailure is wrapped as StepExecutionFailed with
     * the original step exception attached as \Throwable::getPrevious;
     * onComplete-after-onFailure throws bubble out unwrapped, replacing the
     * intended StepExecutionFailed rethrow.
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
            if (is_array($stepClass)) {
                $type = $stepClass['type'] ?? null;

                if ($type === 'nested') {
                    /** @var array<int, string|array<string, mixed>> $innerSteps */
                    $innerSteps = $stepClass['steps'] ?? [];
                    $nestedName = $stepClass['name'] ?? null;

                    $groupConditions = $manifest->stepConditions[$stepIndex] ?? null;
                    $groupConfigs = $manifest->stepConfigs[$stepIndex] ?? null;

                    /** @var array<int, array<string, mixed>|null> $innerConditionsEntries */
                    $innerConditionsEntries = (is_array($groupConditions) && ($groupConditions['type'] ?? null) === 'nested')
                        ? $groupConditions['entries']
                        : [];

                    /** @var array<int, array<string, mixed>> $innerConfigsEntries */
                    $innerConfigsEntries = (is_array($groupConfigs) && ($groupConfigs['type'] ?? null) === 'nested')
                        ? $groupConfigs['configs']
                        : [];

                    SyncNestedPipelineRunner::run(
                        $manifest,
                        $stepIndex,
                        $innerSteps,
                        $nestedName,
                        $innerConditionsEntries,
                        $innerConfigsEntries,
                    );

                    continue;
                }

                if ($type === 'branch') {
                    SyncConditionalBranchRunner::run($manifest, $stepIndex, $stepClass);

                    continue;
                }

                /** @var array<int, string> $parallelClasses */
                $parallelClasses = $stepClass['classes'] ?? [];
                SyncParallelGroupRunner::executeGroup($manifest, $stepIndex, $parallelClasses);

                continue;
            }

            try {
                if (StepConditionEvaluator::shouldSkipStep($manifest, $stepIndex)) {
                    $manifest->advanceStep();

                    continue;
                }

                $job = app()->make($stepClass);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                StepInvoker::fireHooks(
                    $manifest->beforeEachHooks,
                    StepDefinition::fromJobClass($stepClass),
                    $manifest->context,
                );

                StepInvoker::invokeWithRetry(
                    $job,
                    $manifest->stepConfigs[$stepIndex] ?? ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
                );

                StepInvoker::fireHooks(
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
                    StepInvoker::fireHooks(
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
                    SyncCompensationRunner::run($manifest);
                }

                // Story 6.2 AC #2, #11: pipeline-level onFailure fires AFTER
                // per-step onStepFailed (Story 6.1) AND AFTER compensation
                // (under StopAndCompensate) AND BEFORE the terminal rethrow.
                // Under SkipAndContinue this block is unreachable (AC #10).
                try {
                    StepInvoker::firePipelineCallback(
                        $manifest->onFailureCallback,
                        $manifest->context,
                        $exception,
                    );
                } catch (Throwable $callbackException) {
                    // AC #12 sync failure path: a throwing onFailure replaces
                    // the original step exception as the bubbling Throwable;
                    // the original is preserved on
                    // StepExecutionFailed::$originalStepException so
                    // observability is retained. onComplete is NOT called.
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $stepClass,
                        $callbackException,
                        $exception,
                    );
                }

                try {
                    StepInvoker::firePipelineCallback($manifest->onCompleteCallback, $manifest->context);
                } catch (Throwable $callbackException) {
                    // AC #12 sync failure path: a throwing onComplete replaces
                    // the originally-intended StepExecutionFailed rethrow; the
                    // original step exception is preserved on
                    // StepExecutionFailed::$originalStepException.
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $stepClass,
                        $callbackException,
                        $exception,
                    );
                }

                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $manifest->currentStepIndex,
                    $stepClass,
                    $exception,
                );
            }
        }

        // Story 6.2 AC #1, #3, #4: onSuccess fires on terminal success, then
        // onComplete. A throw from onSuccess short-circuits onComplete
        // naturally (AC #12); a throw from onComplete bubbles out unwrapped.
        StepInvoker::firePipelineCallback($manifest->onSuccessCallback, $manifest->context);
        StepInvoker::firePipelineCallback($manifest->onCompleteCallback, $manifest->context);

        return $manifest->context;
    }
}
