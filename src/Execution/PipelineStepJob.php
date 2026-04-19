<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\Queued\QueuedCompensationDispatcher;
use Vherbaut\LaravelPipelineJobs\Execution\Queued\QueuedConditionalBranchHandler;
use Vherbaut\LaravelPipelineJobs\Execution\Queued\QueuedParallelBatchCoordinator;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\PipelineEventDispatcher;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepConditionEvaluator;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvoker;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Internal queued wrapper that executes a single pipeline step and chains the next.
 *
 * Not part of the public API. Carries the full PipelineManifest in its
 * serialized payload so the executor can resume from any step on any
 * worker. After handling the current step successfully, the job mutates
 * the manifest (markStepCompleted + advanceStep) and self-dispatches the
 * next PipelineStepJob until the pipeline is complete. Failures are logged
 * with pipeline context and rethrown so Laravel's native queue failure
 * handling (failed_jobs, retry, failed()) fires for the wrapper job.
 *
 * When the manifest's failStrategy is StopAndCompensate, the wrapper job
 * also dispatches a Bus::chain() of CompensationStepJob instances in
 * reverse order of the completed steps before rethrowing, so each
 * compensation runs on a fresh worker with standard Laravel retry.
 *
 * Each next-step dispatch consults `stepConfigs[nextIndex]` to select
 * queue, connection, and sync-vs-async mode via `dispatchNextStep()`. When
 * the upcoming step is marked sync, the helper calls `dispatch_sync` so the
 * inline wrapper runs in the current worker's process before `handle()` returns.
 *
 * The step invocation is wrapped in an in-process retry loop driven by
 * `stepConfigs[currentStepIndex]`. When `retry > 0`, a failed `handle()` is
 * re-invoked up to `retry` additional times with `sleep($backoff)` between
 * attempts; the final attempt's exception propagates into the FailStrategy
 * branching. The wrapper's native `$tries` remains locked to 1 — retry is
 * SEMANTIC, not structural. The `timeout` value is applied at dispatch time
 * on the wrapper's public `$timeout` property (see `dispatchNextStep()` and
 * QueuedExecutor::dispatchFirstStep()).
 *
 * @internal
 */
final class PipelineStepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts for this wrapper job.
     *
     * Locked to 1 so a worker crash between a successful step and the recursive
     * dispatch cannot cause the already-succeeded step to re-execute under
     * Laravel's default retry policy.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Per-step wrapper timeout in seconds, read by Laravel's queue worker.
     *
     * Assigned at dispatch time by QueuedExecutor::dispatchFirstStep() and
     * PipelineStepJob::dispatchNextStep() when the resolved step config
     * carries a non-null timeout. Laravel's worker reads this property at
     * job pickup and calls `pcntl_alarm($timeout)` before invoking
     * `handle()`. Null means "use Laravel's default worker timeout".
     *
     * @var int|null
     */
    public ?int $timeout = null;

    /**
     * Create a new pipeline step job.
     *
     * @param PipelineManifest $manifest Mutable manifest tracking steps, index, completed list, and context.
     * @return void
     */
    public function __construct(public PipelineManifest $manifest) {}

    /**
     * Execute the current step and dispatch the next wrapper job if any.
     *
     * Resolves the step class referenced by the manifest's currentStepIndex,
     * injects the manifest into the step via ReflectionProperty when the
     * target job exposes a pipelineManifest property, and invokes handle()
     * through the container. On success, advances the manifest and dispatches
     * the next PipelineStepJob. On failure, records failure context on the
     * manifest and branches on failStrategy:
     *
     * - StopImmediately: logs an error and rethrows so Laravel marks the
     *   wrapper failed and halts the chain.
     * - StopAndCompensate: dispatches the reversed compensation chain, logs
     *   an error, then rethrows to halt the chain.
     * - SkipAndContinue: logs a warning, advances past the failed step,
     *   clears the in-process Throwable reference before forward dispatch
     *   (NFR19 belt-and-suspenders), dispatches the next PipelineStepJob
     *   when more steps remain, and returns successfully. The wrapper is
     *   not marked failed. A later successful step clears the recorded
     *   failure fields; a later failure overwrites them.
     *
     * Per-step lifecycle hooks (Story 6.1) fire synchronously in the
     * current worker process:
     *
     * - beforeEach: fires after the skip check and manifest injection,
     *   immediately before app()->call([$job, 'handle']).
     * - afterEach: fires INSIDE the try block, after handle() returns
     *   successfully, so a throwing afterEach is caught and routed
     *   through the standard failure path (AC #6 symmetry with SyncExecutor).
     * - onStepFailed: fires inside the catch block after failure-field
     *   recording on the manifest and BEFORE FailStrategy branching.
     *
     * Pipeline-level lifecycle callbacks (Story 6.2) fire on the terminal
     * wrapper only:
     *
     * - On the last wrapper's successful completion (currentStepIndex has
     *   advanced past the last step), onSuccess fires first, then onComplete.
     *   Under SkipAndContinue the pipeline reaches this tail regardless of
     *   intermediate skipped steps (AC #10).
     * - On a failing wrapper under StopImmediately / StopAndCompensate,
     *   onFailure fires AFTER onStepFailed, AFTER the compensation chain is
     *   DISPATCHED (queued compensation jobs execute on their own workers
     *   LATER), and BEFORE the terminal rethrow. Callback throws mark the
     *   wrapper failed in Laravel's queue with the callback exception.
     *
     * Hook and callback closures survive queue transport via
     * SerializableClosure (mirrors the stepConditions pattern).
     *
     * @return void
     * @throws Throwable When the underlying step throws under StopImmediately or StopAndCompensate.
     */
    public function handle(): void
    {
        // Cursor-aware position resolution (Story 8.2). When the manifest's
        // nestedCursor is non-empty, the previous wrapper left us inside a
        // nested pipeline at that cursor path; navigate stepClassAt(cursor)
        // to resolve the current entry. Otherwise the cursor-empty path
        // resolves stepClasses[currentStepIndex] exactly as before Story 8.2.
        [$stepClass, $outerIndex] = $this->resolveCurrentStepClass();

        if ($stepClass === null) {
            return;
        }

        if (is_array($stepClass)) {
            $type = $stepClass['type'] ?? null;

            if ($type === 'nested') {
                /** @var array<int, string|array<string, mixed>> $innerSteps */
                $innerSteps = $stepClass['steps'] ?? [];
                $this->handleNestedPipeline($outerIndex, $innerSteps);

                return;
            }

            if ($type === 'branch') {
                QueuedConditionalBranchHandler::handle($this->manifest, $outerIndex, $stepClass);

                return;
            }

            // Parallel shape: the manifest's declared type
            // (`array{type: 'parallel', classes: array<int, string>}`)
            // guarantees the structure. For parallel-inside-nested the
            // passed $groupIndex is cursor[0] (the outermost enclosing
            // outer position) so Horizon batch names stay stable.
            /** @var array<int, string> $parallelClasses */
            $parallelClasses = $stepClass['classes'] ?? [];
            QueuedParallelBatchCoordinator::dispatch($this->manifest, $outerIndex, $parallelClasses);

            return;
        }

        try {
            if (StepConditionEvaluator::shouldSkipAtCursor($this->manifest)) {
                $this->advanceAndContinueOrTerminate(null);

                return;
            }

            $job = app()->make($stepClass);

            if (property_exists($job, 'pipelineManifest')) {
                $property = new ReflectionProperty($job, 'pipelineManifest');
                $property->setValue($job, $this->manifest);
            }

            StepInvoker::fireHooks(
                $this->manifest->beforeEachHooks,
                StepDefinition::fromJobClass($stepClass),
                $this->manifest->context,
            );

            StepInvoker::invokeWithRetry($job, $this->resolveCurrentConfig());

            // Story 6.1 Task 6.4: afterEach fires INSIDE the try block so a
            // throwing afterEach is caught by the standard failure path
            // (symmetric with SyncExecutor per AC #6).
            StepInvoker::fireHooks(
                $this->manifest->afterEachHooks,
                StepDefinition::fromJobClass($stepClass),
                $this->manifest->context,
            );
        } catch (Throwable $exception) {
            // Collapse double-wrapping (deferred-work.md:25): when an inner
            // step is itself a pipeline that threw StepExecutionFailed, unwrap
            // to its underlying cause so the outer frame wraps ONCE.
            $cause = $exception instanceof StepExecutionFailed
                ? ($exception->getPrevious() ?? $exception)
                : $exception;

            // Last-failure-wins: subsequent failures overwrite the recorded fields.
            // For cursor-resolved failures, failedStepIndex records the
            // outermost nested-group position so downstream diagnostics
            // (including CompensationStepJob's manifest inspection) point at
            // the failing group rather than at the inner index.
            $this->manifest->failureException = $cause;
            $this->manifest->failedStepClass = $stepClass;
            $this->manifest->failedStepIndex = $outerIndex;

            // Story 9.1 AC #9: PipelineStepFailed fires BEFORE onStepFailed
            // per-step hooks (queued-mode symmetry with SyncExecutor AC #7).
            // $stepClass is always a flat class-string here: the array-shape
            // branches return earlier in handle(), so this catch only runs
            // for a flat step. For nested inner steps the outer index uses
            // cursor[0] stored in $outerIndex per resolveCurrentStepClass().
            PipelineEventDispatcher::fireStepFailed($this->manifest, $outerIndex, $stepClass, $cause);

            // Story 6.1 AC #3/#7/#8/#9: onStepFailed fires BEFORE FailStrategy
            // branching. A throwing onStepFailed propagates and bypasses the
            // FailStrategy branching for THIS failure (no compensation dispatch,
            // no SkipAndContinue advance; Laravel marks the wrapper failed with
            // the hook exception instead of the original step exception).
            StepInvoker::fireHooks(
                $this->manifest->onStepFailedHooks,
                StepDefinition::fromJobClass($stepClass),
                $this->manifest->context,
                $cause,
            );

            if ($this->manifest->failStrategy === FailStrategy::SkipAndContinue) {
                Log::warning('Pipeline step skipped under SkipAndContinue', [
                    'pipelineId' => $this->manifest->pipelineId,
                    'stepClass' => $stepClass,
                    'stepIndex' => $outerIndex,
                    'nestedCursor' => $this->manifest->nestedCursor,
                    'exception' => $cause->getMessage(),
                ]);

                // NFR19: clear the non-serializable Throwable before dispatching
                // the next wrapper job so the downstream queue payload stays
                // serializable even outside the structural __serialize guard.
                $this->manifest->failureException = null;

                try {
                    $this->advanceAndContinueOrTerminate(null);
                } catch (Throwable $dispatchException) {
                    Log::error('Pipeline next-step dispatch failed under SkipAndContinue', [
                        'pipelineId' => $this->manifest->pipelineId,
                        'nextOuterIndex' => $this->manifest->currentStepIndex,
                        'nestedCursor' => $this->manifest->nestedCursor,
                        'skippedStepClass' => $stepClass,
                        'exception' => $dispatchException->getMessage(),
                    ]);

                    throw $dispatchException;
                }

                return;
            }

            if ($this->manifest->failStrategy === FailStrategy::StopAndCompensate) {
                // Belt-and-suspenders (Story 8.2 Task 7.7): clear the nested
                // cursor before compensation dispatch so the chained
                // CompensationStepJob payloads do not carry stale cursor
                // state into failed_jobs records.
                $this->manifest->nestedCursor = [];
                QueuedCompensationDispatcher::dispatchChain($this->manifest);
            }

            Log::error('Pipeline step failed', [
                'pipelineId' => $this->manifest->pipelineId,
                'currentStepIndex' => $outerIndex,
                'stepClass' => $stepClass,
                'exception' => $cause->getMessage(),
            ]);

            // Story 6.2 AC #2, #7, #11: pipeline-level onFailure fires AFTER
            // per-step onStepFailed (Story 6.1 fireHooks above), AFTER
            // compensation dispatch (queued — the Bus::chain is dispatched;
            // compensation jobs run on their own workers later), AFTER the
            // standard Log::error emission, and BEFORE the wrapper rethrow.
            // Under SkipAndContinue this branch is unreachable (AC #10).
            try {
                StepInvoker::firePipelineCallback(
                    $this->manifest->onFailureCallback,
                    $this->manifest->context,
                    $cause,
                );
            } catch (Throwable $callbackException) {
                throw StepExecutionFailed::forCallbackFailure(
                    $this->manifest->pipelineId,
                    $outerIndex,
                    $stepClass,
                    $callbackException,
                    $cause,
                );
            }

            try {
                StepInvoker::firePipelineCallback($this->manifest->onCompleteCallback, $this->manifest->context);
            } catch (Throwable $callbackException) {
                throw StepExecutionFailed::forCallbackFailure(
                    $this->manifest->pipelineId,
                    $outerIndex,
                    $stepClass,
                    $callbackException,
                    $cause,
                );
            }

            // Story 9.1 AC #9: PipelineCompleted fires at the terminal failure
            // exit of a queued wrapper under StopImmediately / StopAndCompensate,
            // AFTER onFailure + onComplete callbacks and BEFORE the rethrow
            // that marks the wrapper failed in Laravel's queue.
            PipelineEventDispatcher::fireCompleted($this->manifest);

            throw $cause;
        }

        // Story 9.1 AC #9: PipelineStepCompleted fires after afterEach hooks
        // return and BEFORE advanceAndContinueOrTerminate hops to the next
        // wrapper. $outerIndex matches the cursor-aware user-visible outer
        // position for nested/parallel/branch shapes.
        PipelineEventDispatcher::fireStepCompleted($this->manifest, $outerIndex, $stepClass);

        $this->advanceAndContinueOrTerminate($stepClass);
    }

    /**
     * Resolve the current step class and the outer group index for observability.
     *
     * When the manifest's nestedCursor is non-empty, navigates via
     * PipelineManifest::stepClassAt() and returns [$resolvedClass, cursor[0]].
     * When the cursor is empty, falls back to stepClasses[currentStepIndex]
     * and returns [$classAtIndex, currentStepIndex]. Returns [null, 0] when
     * the manifest is exhausted (currentStepIndex past the last outer
     * position AND cursor is empty); callers treat that as a no-op.
     *
     * Cursor navigation failures (LogicException from stepClassAt) clear
     * the cursor and fall through to outer-navigation: a defensive
     * recovery for malformed or legacy cursor payloads.
     *
     * @return array{0: string|array<string, mixed>|null, 1: int} Tuple of resolved entry (class-string, discriminator-tagged shape, or null) and the outermost index used for failure observability.
     */
    private function resolveCurrentStepClass(): array
    {
        if ($this->manifest->nestedCursor !== []) {
            try {
                $resolved = $this->manifest->stepClassAt($this->manifest->nestedCursor);

                return [$resolved, $this->manifest->nestedCursor[0]];
            } catch (LogicException) {
                // Corrupt or legacy cursor: clear and fall through to outer navigation.
                $this->manifest->nestedCursor = [];
            }
        }

        $stepIndex = $this->manifest->currentStepIndex;

        if (! array_key_exists($stepIndex, $this->manifest->stepClasses)) {
            return [null, $stepIndex];
        }

        return [$this->manifest->stepClasses[$stepIndex], $stepIndex];
    }

    /**
     * Initialize (or descend into) the nested cursor and dispatch the first inner step.
     *
     * Called from handle() when the current position resolves to a nested
     * shape. When the manifest's cursor is empty, initializes it to
     * [$groupIndex, 0]; when non-empty (mid-execution descent into a
     * nested-inside-nested shape), appends a fresh 0 for the new inner
     * level. Dispatches a new PipelineStepJob wrapper; the fresh worker's
     * handle() resolves the cursor position and executes the first inner step.
     *
     * An empty $innerSteps array short-circuits to an immediate advance
     * (defensive fallback: NestedPipeline::fromBuilder() builds the inner
     * definition eagerly, which rejects empty steps, so this path is only
     * reached when a hand-crafted manifest is malformed).
     *
     * @param int $groupIndex The outer position of the nested group in the pipeline.
     * @param array<int, string|array<string, mixed>> $innerSteps Inner-step entries of the nested group.
     *
     * @return void
     */
    private function handleNestedPipeline(int $groupIndex, array $innerSteps): void
    {
        if ($innerSteps === []) {
            $this->advanceAndContinueOrTerminate(null);

            return;
        }

        if ($this->manifest->nestedCursor === []) {
            $this->manifest->nestedCursor = [$groupIndex, 0];
        } else {
            $this->manifest->nestedCursor[] = 0;
        }

        $this->dispatchAtCurrentPosition();
    }

    /**
     * Resolve the flat per-step config at the current cursor-or-outer position.
     *
     * Uses PipelineManifest::stepConfigAt() when the cursor is non-empty,
     * else falls back to stepConfigs[currentStepIndex]. Group-shape entries
     * (parallel/nested at the resolved level) or missing entries degrade to
     * the default all-null config shape so the retry loop has a predictable
     * fast-path for legacy or hand-built manifests.
     *
     * @return array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} The resolved flat config.
     */
    private function resolveCurrentConfig(): array
    {
        $default = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

        if ($this->manifest->nestedCursor !== []) {
            $entry = $this->manifest->stepConfigAt($this->manifest->nestedCursor);
        } else {
            $entry = $this->manifest->stepConfigs[$this->manifest->currentStepIndex] ?? $default;
        }

        if (isset($entry['type']) || $entry === []) {
            return $default;
        }

        /** @var array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} $entry */
        return $entry;
    }

    /**
     * Mark the completed step (if any), advance to the next position, and dispatch or terminate.
     *
     * Single exit point for both the success path and the skip path. When a
     * step completed successfully $completedStepClass is its class-string;
     * when the step was skipped (condition, SkipAndContinue recovery), pass
     * null. Clears the three last-failure fields on successful progress so
     * a SkipAndContinue-recovered failure does not linger. Delegates to
     * advanceCursorOrOuter() for cursor/outer navigation, then either
     * dispatches the next wrapper via dispatchAtCurrentPosition() OR fires
     * the terminal onSuccess/onComplete callbacks when the pipeline has
     * exhausted all positions.
     *
     * @param string|null $completedStepClass Class-string of the just-completed step, or null for a skipped step.
     *
     * @return void
     */
    private function advanceAndContinueOrTerminate(?string $completedStepClass): void
    {
        if ($completedStepClass !== null) {
            $this->manifest->markStepCompleted($completedStepClass);

            $this->manifest->failureException = null;
            $this->manifest->failedStepClass = null;
            $this->manifest->failedStepIndex = null;
        }

        self::advanceCursorOrOuter($this->manifest);

        if (self::hasMorePositions($this->manifest)) {
            $this->dispatchAtCurrentPosition();

            return;
        }

        StepInvoker::firePipelineCallback($this->manifest->onSuccessCallback, $this->manifest->context);
        StepInvoker::firePipelineCallback($this->manifest->onCompleteCallback, $this->manifest->context);

        // Story 9.1 AC #9: PipelineCompleted fires once at the terminal
        // queued exit AFTER onSuccess + onComplete callbacks on the success
        // tail (and on the SkipAndContinue success tail, which reaches here
        // through the skip-recovered continuation path).
        PipelineEventDispatcher::fireCompleted($this->manifest);
    }

    /**
     * Advance the manifest to the next position (cursor or outer).
     *
     * When the cursor has a single element, the nested group has just
     * completed: clear the cursor and advance currentStepIndex so the outer
     * pipeline progresses. When the cursor has multiple elements, increment
     * the last element and test in-bounds via stepClassAt(); pop a level on
     * out-of-bounds and repeat until an in-bounds position is found or the
     * cursor reduces to a single element. When the cursor starts empty,
     * simply advance currentStepIndex.
     *
     * Made static so the parallel batch's fan-in closure
     * (finalizeParallelBatch) can reuse the same advancement logic without
     * a PipelineStepJob instance.
     *
     * @param PipelineManifest $manifest The manifest whose cursor / currentStepIndex is advanced in place.
     *
     * @return void
     */
    public static function advanceCursorOrOuter(PipelineManifest $manifest): void
    {
        while ($manifest->nestedCursor !== []) {
            $last = count($manifest->nestedCursor) - 1;

            if ($last === 0) {
                // Exiting the outermost nested group: clear the cursor and
                // bump the outer position past the enclosing group.
                $manifest->nestedCursor = [];
                $manifest->advanceStep();

                return;
            }

            $manifest->nestedCursor[$last]++;

            try {
                $manifest->stepClassAt($manifest->nestedCursor);

                return;
            } catch (LogicException) {
                array_pop($manifest->nestedCursor);
                // Continue the while loop to try the parent level's next position.
            }
        }

        $manifest->advanceStep();
    }

    /**
     * Decide whether the manifest has more positions to execute.
     *
     * Returns true when the nested cursor is non-empty (inside a nested
     * group: the cursor position resolves a valid inner step) OR when the
     * outer currentStepIndex is strictly less than the outer step count.
     *
     * @param PipelineManifest $manifest The manifest to inspect.
     *
     * @return bool True when the pipeline has at least one more position to dispatch.
     */
    public static function hasMorePositions(PipelineManifest $manifest): bool
    {
        return $manifest->nestedCursor !== []
            || $manifest->currentStepIndex < count($manifest->stepClasses);
    }

    /**
     * Dispatch a fresh PipelineStepJob wrapper at the current cursor-or-outer position.
     *
     * Resolves the flat config via PipelineManifest::stepConfigAt() when the
     * cursor is non-empty or stepConfigs[currentStepIndex] otherwise. When
     * the resolved entry is a group-shape (parallel/nested at the resolved
     * level), no per-wrapper queue/connection/timeout overrides apply
     * because the fresh handle() will detect the shape and branch
     * accordingly. For flat-config positions, applies queue / connection /
     * timeout / sync overrides before dispatching. Used by both the
     * instance-level success/skip path and the static parallel fan-in.
     *
     * @return void
     */
    private function dispatchAtCurrentPosition(): void
    {
        self::dispatchWrapperFor($this->manifest);
    }

    /**
     * Dispatch a fresh wrapper for a manifest (static so the batch fan-in can reuse).
     *
     * Same branching logic as dispatchAtCurrentPosition() but operates on an
     * arbitrary manifest. The cursor-or-outer position is derived from the
     * manifest's own state.
     *
     * @param PipelineManifest $manifest The manifest carrying the cursor / outer index / configs for the upcoming step.
     *
     * @return void
     */
    public static function dispatchWrapperFor(PipelineManifest $manifest): void
    {
        if ($manifest->nestedCursor !== []) {
            $config = $manifest->stepConfigAt($manifest->nestedCursor);
        } else {
            $config = $manifest->stepConfigs[$manifest->currentStepIndex] ?? [];
        }

        $job = new self($manifest);

        if (! isset($config['type']) && $config !== []) {
            if (($config['queue'] ?? null) !== null) {
                $job->onQueue($config['queue']);
            }

            if (($config['connection'] ?? null) !== null) {
                $job->onConnection($config['connection']);
            }

            if (($config['timeout'] ?? null) !== null) {
                $job->timeout = $config['timeout'];
            }

            if ((bool) ($config['sync'] ?? false)) {
                dispatch_sync($job);

                return;
            }
        }

        dispatch($job);
    }

    /**
     * Forward a parallel-batch finalization to {@see QueuedParallelBatchCoordinator::finalize()}.
     *
     * Kept as a public static thin proxy because Bus::batch `->finally()`
     * callbacks captured by existing in-flight SerializableClosures reference
     * `PipelineStepJob::finalizeParallelBatch` by FQN. Removing the forward
     * would break any batch already enqueued before the coordinator
     * extraction landed.
     *
     * @param Batch $batch The completed batch instance.
     * @param PipelineManifest $outerManifestSnapshot A pre-batch clone of the outer manifest captured at dispatch time.
     * @param PipelineContext|null $baselineContext The pre-batch context clone used as the merger's baseline.
     * @param string $pipelineId The enclosing pipeline run identifier.
     * @param int $groupIndex The outer position of the parallel group.
     * @param array<int, string> $subStepClasses Sub-step class-strings in declaration order.
     * @param int $subCount Number of sub-steps dispatched in the batch.
     * @return void
     */
    public static function finalizeParallelBatch(
        Batch $batch,
        PipelineManifest $outerManifestSnapshot,
        ?PipelineContext $baselineContext,
        string $pipelineId,
        int $groupIndex,
        array $subStepClasses,
        int $subCount,
    ): void {
        QueuedParallelBatchCoordinator::finalize(
            $batch,
            $outerManifestSnapshot,
            $baselineContext,
            $pipelineId,
            $groupIndex,
            $subStepClasses,
            $subCount,
        );
    }
}
