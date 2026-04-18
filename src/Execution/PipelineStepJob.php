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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
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

            // Parallel shape: the manifest's declared type
            // (`array{type: 'parallel', classes: array<int, string>}`)
            // guarantees the structure. For parallel-inside-nested the
            // passed $groupIndex is cursor[0] (the outermost enclosing
            // outer position) so Horizon batch names stay stable.
            /** @var array<int, string> $parallelClasses */
            $parallelClasses = $stepClass['classes'] ?? [];
            $this->dispatchParallelBatch($outerIndex, $parallelClasses);

            return;
        }

        try {
            if ($this->shouldSkipAtCurrentPosition()) {
                $this->advanceAndContinueOrTerminate(null);

                return;
            }

            $job = app()->make($stepClass);

            if (property_exists($job, 'pipelineManifest')) {
                $property = new ReflectionProperty($job, 'pipelineManifest');
                $property->setValue($job, $this->manifest);
            }

            $this->fireHooks(
                $this->manifest->beforeEachHooks,
                StepDefinition::fromJobClass($stepClass),
                $this->manifest->context,
            );

            $this->invokeStepWithRetry($job, $this->resolveCurrentConfig());

            // Story 6.1 Task 6.4: afterEach fires INSIDE the try block so a
            // throwing afterEach is caught by the standard failure path
            // (symmetric with SyncExecutor per AC #6).
            $this->fireHooks(
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

            // Story 6.1 AC #3/#7/#8/#9: onStepFailed fires BEFORE FailStrategy
            // branching. A throwing onStepFailed propagates and bypasses the
            // FailStrategy branching for THIS failure (no compensation dispatch,
            // no SkipAndContinue advance; Laravel marks the wrapper failed with
            // the hook exception instead of the original step exception).
            $this->fireHooks(
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
                $this->dispatchCompensationChain();
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
                $this->firePipelineCallback(
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
                $this->firePipelineCallback($this->manifest->onCompleteCallback, $this->manifest->context);
            } catch (Throwable $callbackException) {
                throw StepExecutionFailed::forCallbackFailure(
                    $this->manifest->pipelineId,
                    $outerIndex,
                    $stepClass,
                    $callbackException,
                    $cause,
                );
            }

            throw $cause;
        }

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
     * Decide whether the step at the current cursor-or-outer position should be skipped.
     *
     * Uses PipelineManifest::conditionAt() when the cursor is non-empty
     * (returns the resolved flat condition entry for the cursor path, or
     * null for unconditional / group-shape entries). Falls back to the
     * outer-index lookup when the cursor is empty. Mirrors
     * SyncExecutor::shouldSkipStep() semantics: closure is unwrapped via
     * getClosure(), result is cast to bool, and the `negated` flag is
     * applied. A throwing closure propagates so the outer try/catch in
     * handle() converts it to the standard failure path.
     *
     * @return bool True when the step must be skipped, false when it should run.
     */
    private function shouldSkipAtCurrentPosition(): bool
    {
        if ($this->manifest->nestedCursor !== []) {
            $entry = $this->manifest->conditionAt($this->manifest->nestedCursor);
        } else {
            $outer = $this->manifest->stepConditions[$this->manifest->currentStepIndex] ?? null;
            $entry = (is_array($outer) && ! isset($outer['type'])) ? $outer : null;
        }

        if ($entry === null) {
            return false;
        }

        $closure = $entry['closure']->getClosure();
        $result = (bool) $closure($this->manifest->context);
        $shouldRun = $entry['negated'] ? ! $result : $result;

        return ! $shouldRun;
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

        $this->firePipelineCallback($this->manifest->onSuccessCallback, $this->manifest->context);
        $this->firePipelineCallback($this->manifest->onCompleteCallback, $this->manifest->context);
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
     * Invoke the step's handle() method with an in-process retry loop.
     *
     * Accepts the pre-resolved per-step configuration (cursor-aware when
     * called from handle()'s cursor-or-outer flow, or the outer-index
     * lookup when the cursor is empty). Fast path: when retry is null or
     * zero, `app()->call([$job, 'handle'])` runs once — zero retry-loop
     * overhead when retry is unset. Retry path: `retry + 1` attempts with
     * `sleep($backoff)` between non-final attempts. The final attempt's
     * exception propagates to the outer catch where FailStrategy branching
     * takes over.
     *
     * Instance-reuse contract: the step `$job` is resolved ONCE by the
     * caller (`app()->make($stepClass)`) before this helper runs; the SAME
     * instance receives every retry attempt. Instance-level state
     * (counters, accumulators, cached service handles) persists across
     * attempts. This differs from Laravel's native queue retry which
     * re-resolves per attempt because it crosses process boundaries; the
     * in-process retry here stays inside one PHP process and therefore
     * preserves the instance. Users relying on step-local state should
     * expect this semantic.
     *
     * The retry loop runs inside the CURRENT worker's PHP process. Each
     * attempt blocks the worker for `sleep($backoff)` seconds plus the
     * attempt's runtime. A cumulative attempts + backoffs window exceeding
     * the wrapper's `$timeout` (when set by the dispatch helper) triggers
     * `SIGALRM` termination mid-retry — the in-process loop does not
     * protect against timeout.
     *
     * The `timeout` key of the config is intentionally NOT consulted here;
     * it is applied at dispatch time on the wrapper's public `$timeout`
     * property, which Laravel's worker reads via `pcntl_alarm()`.
     *
     * @param object $job The resolved step job instance (already has manifest injected when applicable).
     * @param array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} $config The pre-resolved per-step config; retry/backoff drive the loop, timeout is ignored here.
     * @return void
     *
     * @throws Throwable The final attempt's exception when the retry loop exhausts.
     */
    private function invokeStepWithRetry(object $job, array $config): void
    {
        $retry = $config['retry'] ?? null;

        if ($retry === null || $retry === 0) {
            app()->call([$job, 'handle']);

            return;
        }

        $backoff = $config['backoff'] ?? 0;
        $maxAttempts = $retry + 1;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                app()->call([$job, 'handle']);

                return;
            } catch (Throwable $exception) {
                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }

                if ($backoff > 0) {
                    sleep($backoff);
                }
            }
        }
    }

    /**
     * Invoke a hook array in registration order with the appropriate arguments.
     *
     * Unwraps each SerializableClosure and calls it with either
     * ($step, $context) for beforeEach/afterEach (exception is null) or
     * ($step, $context, $exception) for onStepFailed. Hook exceptions
     * propagate on first throw: the loop aborts and subsequent hooks in
     * the array are NOT invoked (Story 6.1 AC #7 no-silent-swallow).
     *
     * Mirrors SyncExecutor::fireHooks() and RecordingExecutor::fireHooks();
     * intentional three-site duplication per Story 5.2 Design Decision #2.
     *
     * @param array<int, SerializableClosure> $hooks Ordered list of wrapped hook closures.
     * @param StepDefinition $step Minimal snapshot of the currently executing step.
     * @param PipelineContext|null $context The live pipeline context, or null when no context was sent.
     * @param Throwable|null $exception The caught throwable for onStepFailed hooks; null for beforeEach/afterEach.
     * @return void
     */
    private function fireHooks(array $hooks, StepDefinition $step, ?PipelineContext $context, ?Throwable $exception = null): void
    {
        foreach ($hooks as $hook) {
            $closure = $hook->getClosure();

            if ($exception === null) {
                $closure($step, $context);

                continue;
            }

            $closure($step, $context, $exception);
        }
    }

    /**
     * Invoke a pipeline-level callback with the appropriate argument set.
     *
     * Mirrors SyncExecutor::firePipelineCallback() and
     * RecordingExecutor::firePipelineCallback() per Story 5.2 Design
     * Decision #2 (three-site duplication over shared helper). Null-guards
     * on the callback slot (zero-overhead fast path, AC #6); unwraps via
     * getClosure() and invokes with ($context) for onSuccess/onComplete
     * or ($context, $exception) for onFailure. A throw from the closure
     * propagates unchanged (architecture.md:395); the caller handles the
     * queued-wrapper failure semantics (AC #12).
     *
     * @param SerializableClosure|null $callback The wrapped pipeline-level callback, or null when not registered.
     * @param PipelineContext|null $context The live pipeline context at firing time (may be null).
     * @param Throwable|null $exception The caught throwable for onFailure; null for onSuccess/onComplete.
     * @return void
     */
    private function firePipelineCallback(
        ?SerializableClosure $callback,
        ?PipelineContext $context,
        ?Throwable $exception = null,
    ): void {
        if ($callback === null) {
            return;
        }

        $closure = $callback->getClosure();

        if ($exception === null) {
            $closure($context);

            return;
        }

        $closure($context, $exception);
    }

    /**
     * Dispatch a Bus::batch fan-out for one parallel group and register the fan-in continuation.
     *
     * Builds one ParallelStepJob wrapper per sub-step, each carrying its own
     * deep-clone of the manifest (and therefore its own PipelineContext
     * clone) so sub-steps run with no shared mutable state during the batch
     * window. Applies per-sub-step queue/connection/timeout overrides from
     * the nested $stepConfigs[$groupIndex]['configs'] shape before pushing
     * each wrapper into the batch.
     *
     * The batch is configured with:
     *  - `->name("pipeline:{pipelineId}:parallel:{groupIndex}")` for Horizon
     *    observability.
     *  - `->allowFailures()` so siblings run to completion under any fail
     *    strategy; FailStrategy branching happens in the fan-in continuation.
     *  - `->finally($closure)` registered as a SerializableClosure-wrapped
     *    callable capturing the group index, sub-step count, outer manifest
     *    snapshot, and pre-batch context baseline. `finally` (not `then`) is
     *    required here because Laravel's `then` only fires when pending_jobs
     *    hits 0, and `recordFailedJob` does NOT decrement pending_jobs under
     *    allowFailures() — so a `then`-based fan-in would hang whenever any
     *    sub-step fails. `finally` fires when allJobsHaveRanExactlyOnce()
     *    returns true (pending - failed === 0), which covers both all-success
     *    and partial-failure paths. The closure recovers per-sub-step final
     *    contexts from cache via ParallelStepJob::contextCacheKey(), merges
     *    them via ParallelContextMerger::merge(), rebuilds a fresh manifest
     *    with the merged context, and either dispatches the next
     *    PipelineStepJob (on success or SkipAndContinue), dispatches the
     *    compensation chain (on StopAndCompensate), or fires terminal
     *    onFailure/onComplete callbacks (on StopImmediately).
     *  - `->catch($closure)` registered for observability only — logs the
     *    first failure so operators see a signal before the fan-in pass.
     *    Does NOT drive control flow.
     *
     * The outer PipelineStepJob returns immediately after the batch is
     * dispatched; the next-step dispatch lives entirely inside the `finally`
     * callback to avoid racing with the asynchronous batch completion.
     *
     * @param int $groupIndex The outer pipeline position of the parallel group.
     * @param array<int, string> $subStepClasses Sub-step class-strings in declaration order.
     * @return void
     */
    private function dispatchParallelBatch(int $groupIndex, array $subStepClasses): void
    {
        $baselineContext = $this->manifest->context === null
            ? null
            : unserialize(serialize($this->manifest->context));

        // Resolve the effective parallel-shape config entry once. For a
        // top-level parallel group this is stepConfigs[$groupIndex]. For a
        // parallel group nested inside a NestedPipeline the enclosing entry
        // is a nested shape whose inner configs[$innerIndex] holds the
        // parallel shape; stepConfigAt() navigates the cursor path to fetch
        // it. Missing or malformed entries degrade to null so the downstream
        // lookup falls back to the default null-config shape.
        $parallelShape = $this->manifest->nestedCursor !== []
            ? $this->manifest->stepConfigAt($this->manifest->nestedCursor)
            : ($this->manifest->stepConfigs[$groupIndex] ?? null);

        if (! is_array($parallelShape) || ($parallelShape['type'] ?? null) !== 'parallel') {
            $parallelShape = null;
        }

        $jobs = [];

        foreach ($subStepClasses as $subIndex => $subStepClass) {
            // Inject the resolved parallel shape at the cloned manifest's
            // $groupIndex slot so ParallelStepJob::resolveSubStepConfig() (a
            // forbidden-edit file) finds the per-sub-step config entries
            // under stepConfigs[$groupIndex]['configs'][$subIndex] even for
            // parallel-inside-nested, where the real nested tree carries it
            // at stepConfigs[cursor[0]]['configs'][cursor[1]]. Re-keying
            // goes through withRekeyedStepConfig() because stepConfigs is
            // readonly and can only be assigned via __unserialize.
            if ($parallelShape !== null && $this->manifest->nestedCursor !== []) {
                $clonedManifest = $this->manifest->withRekeyedStepConfig($groupIndex, $parallelShape);
            } else {
                /** @var PipelineManifest $clonedManifest */
                $clonedManifest = unserialize(serialize($this->manifest));
            }

            $wrapper = new ParallelStepJob(
                manifest: $clonedManifest,
                groupIndex: $groupIndex,
                subStepIndex: $subIndex,
                stepClass: $subStepClass,
            );

            $config = $this->resolveParallelSubConfigFrom($parallelShape, $subIndex);

            if ($config['queue'] !== null) {
                $wrapper->onQueue($config['queue']);
            }

            if ($config['connection'] !== null) {
                $wrapper->onConnection($config['connection']);
            }

            if ($config['timeout'] !== null) {
                $wrapper->timeout = $config['timeout'];
            }

            $jobs[] = $wrapper;
        }

        $pipelineId = $this->manifest->pipelineId;
        $outerManifestSnapshot = unserialize(serialize($this->manifest));
        $subCount = count($subStepClasses);

        // Fan-in continuation: registered as `->finally()` so it fires once
        // all sub-step jobs have RUN (pending - failed === 0), not only when
        // they all succeeded. Laravel's `->then()` is wired to
        // recordSuccessfulJob and only fires when pending_jobs hits 0;
        // recordFailedJob increments failed_jobs WITHOUT decrementing
        // pending_jobs, so any failure under allowFailures() would leave a
        // then-based fan-in stranded forever. `->finally()` is the only
        // native hook that covers both the all-success and partial-failure
        // paths, which is the correct semantic for FailStrategy branching.
        $finalizeCallback = new SerializableClosure(function (Batch $batch) use (
            $outerManifestSnapshot,
            $baselineContext,
            $pipelineId,
            $groupIndex,
            $subStepClasses,
            $subCount,
        ): void {
            PipelineStepJob::finalizeParallelBatch(
                $batch,
                $outerManifestSnapshot,
                $baselineContext,
                $pipelineId,
                $groupIndex,
                $subStepClasses,
                $subCount,
            );
        });

        // Observability hook: log the first batch-job failure so operators
        // get an early signal before the fan-in aggregate pass. `catch`
        // fires once on the first failure regardless of allowFailures(); it
        // is additive observability, not control flow.
        $catchCallback = new SerializableClosure(function (Batch $batch, Throwable $exception) use ($pipelineId, $groupIndex): void {
            Log::warning('Pipeline parallel batch caught a sub-step failure', [
                'pipelineId' => $pipelineId,
                'groupIndex' => $groupIndex,
                'exception' => $exception->getMessage(),
            ]);
        });

        try {
            Bus::batch($jobs)
                ->name("pipeline:{$pipelineId}:parallel:{$groupIndex}")
                ->allowFailures()
                ->finally($finalizeCallback->getClosure())
                ->catch($catchCallback->getClosure())
                ->dispatch();
        } catch (Throwable $dispatchException) {
            Log::error('Pipeline parallel batch dispatch failed', [
                'pipelineId' => $pipelineId,
                'groupIndex' => $groupIndex,
                'exception' => $dispatchException->getMessage(),
            ]);

            throw $dispatchException;
        }
    }

    /**
     * Finalize a parallel batch's fan-in: merge contexts, apply FailStrategy, and continue the pipeline.
     *
     * Exposed as a public static method so the dispatchParallelBatch()
     * SerializableClosure has a stable call-site to forward to. Recovers
     * per-sub-step final contexts from Laravel cache (written by
     * ParallelStepJob::handle()), merges them into the baseline via
     * ParallelContextMerger, clears the cache entries, and branches on the
     * manifest's FailStrategy when the batch reports failures:
     *  - Under StopAndCompensate or StopImmediately with any failure, fires
     *    onFailure then onComplete and does NOT dispatch the next step.
     *    StopAndCompensate additionally dispatches the reversed
     *    CompensationStepJob chain (flat $completedSteps drives the
     *    selection; successful sub-steps in this group ARE included).
     *  - Under SkipAndContinue (or any strategy with no failures) the merged
     *    manifest is advanced past the group and the next PipelineStepJob
     *    is dispatched via dispatchNextStep() (which re-enters the standard
     *    per-step config resolution for the upcoming position).
     *  - When the group is the last step in the pipeline, the merged
     *    manifest's success tail fires onSuccess then onComplete to match
     *    the queued-mode terminal-callback contract.
     *
     * Runs on whichever worker picks up the batch-finalization job; Laravel
     * guarantees this runs exactly once per batch.
     *
     * @internal Public-static is required only so dispatchParallelBatch()'s SerializableClosure has a stable forwarding target; callers outside this package should not invoke it directly.
     *
     * @param Batch $batch The completed batch instance (carries hasFailures() observability).
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
        $finalContexts = [];
        $succeededIndices = [];

        // Read (without deleting) the succeeded signal and the optional
        // context entry. Cache::get + Cache::forget (instead of Cache::pull)
        // leaves the entry readable if the `then` callback retries after a
        // downstream dispatch failure; the explicit forget at the end of the
        // loop still cleans up on the normal path.
        for ($i = 0; $i < $subCount; $i++) {
            $succeededKey = ParallelStepJob::succeededCacheKey($pipelineId, $groupIndex, $i);
            $contextKey = ParallelStepJob::contextCacheKey($pipelineId, $groupIndex, $i);

            $succeededIndices[$i] = Cache::get($succeededKey) === true;

            if ($succeededIndices[$i]) {
                $cached = Cache::get($contextKey);
                $finalContexts[$i] = $cached instanceof PipelineContext ? $cached : null;
            } else {
                $finalContexts[$i] = null;
            }

            Cache::forget($succeededKey);
            Cache::forget($contextKey);
        }

        $mergedContext = ParallelContextMerger::merge(
            $baselineContext,
            $finalContexts,
            $pipelineId,
            $groupIndex,
        );

        $manifest = $outerManifestSnapshot;
        $manifest->context = $mergedContext;

        // Drive markStepCompleted from the succeeded signal (works for both
        // context-less pipelines and contexts that legitimately come back
        // null) rather than from context presence alone.
        foreach ($subStepClasses as $subIndex => $subStepClass) {
            if (($succeededIndices[$subIndex] ?? false) === true) {
                $manifest->markStepCompleted($subStepClass);
            }
        }

        $hasFailures = $batch->hasFailures();
        $strategy = $manifest->failStrategy;

        if ($hasFailures && $strategy !== FailStrategy::SkipAndContinue) {
            // Record the group-level failure index so downstream
            // CompensationStepJob handlers (and debuggers) can introspect
            // $manifest->failedStepIndex to find the parallel-group position.
            // failedStepClass is left unset because Bus::batch()'s fan-in API
            // does not expose per-job classes to the then/catch callbacks.
            $manifest->failedStepIndex = $groupIndex;

            $failureException = new StepExecutionFailed(
                "Pipeline [{$pipelineId}] parallel group at position {$groupIndex} failed under {$strategy->name}.",
            );

            Log::error('Pipeline parallel batch reported failures', [
                'pipelineId' => $pipelineId,
                'groupIndex' => $groupIndex,
                'failedCount' => $batch->failedJobs,
                'strategy' => $strategy->name,
            ]);

            if ($strategy === FailStrategy::StopAndCompensate) {
                // Belt-and-suspenders (Story 8.2 Task 7.7): clear the nested
                // cursor before the compensation chain is serialized so
                // failed_jobs records do not carry stale cursor state.
                $manifest->nestedCursor = [];
                self::dispatchCompensationChainFor($manifest);
            }

            // Wrap the callback invocations so a throwing user callback is
            // logged with full fan-in context rather than raw-propagating
            // out of the batch-then worker. The fan-in path is terminal:
            // rethrowing here would land the then-callback job in
            // failed_jobs without any recovery path, so we log and continue
            // to onComplete.
            if ($manifest->onFailureCallback !== null) {
                try {
                    ($manifest->onFailureCallback->getClosure())($manifest->context, $failureException);
                } catch (Throwable $callbackException) {
                    Log::error('Pipeline onFailure callback threw during parallel fan-in', [
                        'pipelineId' => $pipelineId,
                        'groupIndex' => $groupIndex,
                        'exception' => $callbackException->getMessage(),
                    ]);
                }
            }

            if ($manifest->onCompleteCallback !== null) {
                try {
                    ($manifest->onCompleteCallback->getClosure())($manifest->context);
                } catch (Throwable $callbackException) {
                    Log::error('Pipeline onComplete callback threw during parallel fan-in', [
                        'pipelineId' => $pipelineId,
                        'groupIndex' => $groupIndex,
                        'exception' => $callbackException->getMessage(),
                    ]);
                }
            }

            return;
        }

        // No terminal failure: advance past the group before dispatching
        // the next step or firing the success tail. Placing advancement
        // AFTER the hasFailures() branch preserves the "failed group stays
        // at its position" invariant that mirrors the sync path (failures
        // leave currentStepIndex pointing at the failing group for
        // observability / downstream diagnostics).
        //
        // Cursor-aware advancement (Story 8.2): when the parallel group is
        // nested inside a wrapping nested pipeline, the manifest carries a
        // non-empty nestedCursor pointing at the parallel-shape entry's
        // inner position. advanceCursorOrOuter() increments the inner index
        // (or pops up one level when the inner list is exhausted) instead
        // of advancing currentStepIndex past the outermost nested group.
        self::advanceCursorOrOuter($manifest);

        if (self::hasMorePositions($manifest)) {
            self::dispatchWrapperFor($manifest);

            return;
        }

        if ($manifest->onSuccessCallback !== null) {
            ($manifest->onSuccessCallback->getClosure())($manifest->context);
        }

        if ($manifest->onCompleteCallback !== null) {
            ($manifest->onCompleteCallback->getClosure())($manifest->context);
        }
    }

    /**
     * Dispatch the reversed compensation chain for a given manifest (static helper for batch finalization).
     *
     * Mirrors the instance-bound dispatchCompensationChain() but operates on
     * an arbitrary manifest reference so the batch-finalization static
     * helper can reuse the same reversal/dispatch logic. Silently
     * short-circuits when no completed step has a compensation mapping.
     *
     * @param PipelineManifest $manifest The manifest whose completedSteps drive the reversed compensation chain.
     * @return void
     */
    private static function dispatchCompensationChainFor(PipelineManifest $manifest): void
    {
        if ($manifest->compensationMapping === []) {
            return;
        }

        $chain = [];
        $reversedCompleted = array_reverse($manifest->completedSteps);

        foreach ($reversedCompleted as $completedStep) {
            if (! isset($manifest->compensationMapping[$completedStep])) {
                continue;
            }

            $chain[] = new CompensationStepJob(
                $manifest->compensationMapping[$completedStep],
                $manifest,
            );
        }

        if ($chain === []) {
            return;
        }

        $manifest->failureException = null;

        try {
            Bus::chain($chain)->dispatch();
        } catch (Throwable $dispatchException) {
            Log::error('Pipeline compensation chain dispatch failed', [
                'pipelineId' => $manifest->pipelineId,
                'failedStepClass' => $manifest->failedStepClass,
                'exception' => $dispatchException->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the per-sub-step config entry from a pre-resolved parallel shape.
     *
     * Reads `$parallelShape['configs'][$subIndex]` and falls back to the
     * default null-config shape when the shape is null, malformed, or missing
     * the requested sub-step. Defensive against hand-built manifests that
     * skip the PipelineBuilder's nested-shape generation as well as against
     * parallel-inside-nested entries where the caller (dispatchParallelBatch)
     * navigates the nested tree via stepConfigAt() before calling this
     * helper.
     *
     * @param array<string, mixed>|null $parallelShape The resolved parallel shape (already navigated via stepConfigAt when inside a nested group).
     * @param int $subIndex The zero-based declaration-order index of the sub-step.
     *
     * @return array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} The resolved per-sub-step configuration.
     */
    private function resolveParallelSubConfigFrom(?array $parallelShape, int $subIndex): array
    {
        $default = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

        if ($parallelShape === null || ($parallelShape['type'] ?? null) !== 'parallel') {
            return $default;
        }

        /** @var array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}> $configs */
        $configs = $parallelShape['configs'] ?? [];

        return $configs[$subIndex] ?? $default;
    }

    /**
     * Dispatch the reversed compensation chain as a Bus::chain of CompensationStepJob.
     *
     * Reverses $this->manifest->completedSteps, looks up the compensation
     * class for each completed step in $this->manifest->compensationMapping,
     * and accumulates a CompensationStepJob wrapper per mapped entry. When
     * the resulting array is non-empty, dispatches Bus::chain($jobs) so each
     * compensation runs on its own worker in reverse order. Short-circuits
     * to no dispatch when no completed step declared a compensation.
     *
     * Called from the failure branch of handle() only when the manifest's
     * failStrategy is FailStrategy::StopAndCompensate.
     *
     * @return void
     */
    private function dispatchCompensationChain(): void
    {
        if ($this->manifest->compensationMapping === []) {
            return;
        }

        $chain = [];
        $reversedCompleted = array_reverse($this->manifest->completedSteps);

        foreach ($reversedCompleted as $completedStep) {
            if (! isset($this->manifest->compensationMapping[$completedStep])) {
                continue;
            }

            $chain[] = new CompensationStepJob(
                $this->manifest->compensationMapping[$completedStep],
                $this->manifest,
            );
        }

        if ($chain === []) {
            return;
        }

        // NFR19: clear the non-serializable throwable from the manifest BEFORE
        // Bus::chain() serializes each CompensationStepJob's payload. The
        // wrapped jobs share the same manifest reference, so nulling here
        // protects every queued compensation payload.
        $this->manifest->failureException = null;

        try {
            Bus::chain($chain)->dispatch();
        } catch (Throwable $dispatchException) {
            // A failure to dispatch the compensation chain (queue driver
            // unavailable, serialization failure) must not mask the original
            // step exception. Log the dispatch failure and let handle()
            // rethrow the real step exception caught upstream.
            Log::error('Pipeline compensation chain dispatch failed', [
                'pipelineId' => $this->manifest->pipelineId,
                'failedStepClass' => $this->manifest->failedStepClass,
                'exception' => $dispatchException->getMessage(),
            ]);
        }
    }
}
