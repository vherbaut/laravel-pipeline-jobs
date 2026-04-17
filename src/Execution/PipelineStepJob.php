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
        $stepIndex = $this->manifest->currentStepIndex;

        if (! array_key_exists($stepIndex, $this->manifest->stepClasses)) {
            return;
        }

        $stepClass = $this->manifest->stepClasses[$stepIndex];

        if (is_array($stepClass)) {
            // Parallel-group position: the manifest's declared type
            // (`array{type: 'parallel', classes: array<int, string>}`)
            // guarantees the shape, so no runtime check is needed. Hook
            // firing is delegated to ParallelStepJob::handle() per
            // sub-step (the outer wrapper fires no hooks for a parallel
            // position).
            $this->dispatchParallelBatch($stepIndex, $stepClass['classes']);

            return;
        }

        try {
            if ($this->shouldSkip($stepIndex)) {
                $this->manifest->advanceStep();

                if ($this->manifest->currentStepIndex < count($this->manifest->stepClasses)) {
                    $this->dispatchNextStep();

                    return;
                }

                // Story 6.2 AC #1 / AC #10: a conditionally-skipped last step
                // still terminates the pipeline on this wrapper. Fire the
                // terminal callbacks so queued-mode parity with SyncExecutor
                // is preserved when the last step is skipped by when()/unless().
                $this->firePipelineCallback($this->manifest->onSuccessCallback, $this->manifest->context);
                $this->firePipelineCallback($this->manifest->onCompleteCallback, $this->manifest->context);

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

            $this->invokeStepWithRetry($job);

            // Story 6.1 Task 6.4: afterEach fires INSIDE the try block so a
            // throwing afterEach is caught by the standard failure path
            // (symmetric with SyncExecutor per AC #6).
            $this->fireHooks(
                $this->manifest->afterEachHooks,
                StepDefinition::fromJobClass($stepClass),
                $this->manifest->context,
            );
        } catch (Throwable $exception) {
            // Last-failure-wins: subsequent failures overwrite the recorded fields.
            $this->manifest->failureException = $exception;
            $this->manifest->failedStepClass = $stepClass;
            $this->manifest->failedStepIndex = $stepIndex;

            // Story 6.1 AC #3/#7/#8/#9: onStepFailed fires BEFORE FailStrategy
            // branching. A throwing onStepFailed propagates and bypasses the
            // FailStrategy branching for THIS failure (no compensation dispatch,
            // no SkipAndContinue advance; Laravel marks the wrapper failed with
            // the hook exception instead of the original step exception).
            $this->fireHooks(
                $this->manifest->onStepFailedHooks,
                StepDefinition::fromJobClass($stepClass),
                $this->manifest->context,
                $exception,
            );

            if ($this->manifest->failStrategy === FailStrategy::SkipAndContinue) {
                Log::warning('Pipeline step skipped under SkipAndContinue', [
                    'pipelineId' => $this->manifest->pipelineId,
                    'stepClass' => $stepClass,
                    'stepIndex' => $stepIndex,
                    'exception' => $exception->getMessage(),
                ]);

                $this->manifest->advanceStep();

                // NFR19: clear the non-serializable Throwable before dispatching
                // the next wrapper job so the downstream queue payload stays
                // serializable even outside the structural __serialize guard.
                $this->manifest->failureException = null;

                if ($this->manifest->currentStepIndex < count($this->manifest->stepClasses)) {
                    try {
                        // dispatch_sync may also throw here under Story 7.1's
                        // sync-step branch; the same catch block handles both
                        // because dispatch_sync surfaces exceptions synchronously.
                        $this->dispatchNextStep();
                    } catch (Throwable $dispatchException) {
                        // If dispatch() itself throws (queue driver unavailable,
                        // serialization failure), Laravel's default handling lands
                        // the wrapper in failed_jobs. Log the dispatch-site context
                        // before rethrow so operators can attribute the failure to
                        // the dispatch, not to the already-skipped step.
                        Log::error('Pipeline next-step dispatch failed under SkipAndContinue', [
                            'pipelineId' => $this->manifest->pipelineId,
                            'nextStepIndex' => $this->manifest->currentStepIndex,
                            'skippedStepClass' => $stepClass,
                            'exception' => $dispatchException->getMessage(),
                        ]);

                        throw $dispatchException;
                    }

                    return;
                }

                // Story 6.2 AC #1 / AC #10: SkipAndContinue on the last step
                // still terminates the pipeline on this wrapper with a
                // "success" outcome (the strategy converts intermediate
                // failures into continuations). Fire terminal callbacks so
                // queued-mode parity with SyncExecutor is preserved.
                $this->firePipelineCallback($this->manifest->onSuccessCallback, $this->manifest->context);
                $this->firePipelineCallback($this->manifest->onCompleteCallback, $this->manifest->context);

                return;
            }

            if ($this->manifest->failStrategy === FailStrategy::StopAndCompensate) {
                $this->dispatchCompensationChain();
            }

            Log::error('Pipeline step failed', [
                'pipelineId' => $this->manifest->pipelineId,
                'currentStepIndex' => $stepIndex,
                'stepClass' => $stepClass,
                'exception' => $exception->getMessage(),
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
                    $exception,
                );
            } catch (Throwable $callbackException) {
                // AC #5 queued parity: wrap the callback exception in
                // StepExecutionFailed so failed_jobs carries the same
                // envelope as SyncExecutor / RecordingExecutor. The
                // original step exception is preserved on
                // StepExecutionFailed::$originalStepException.
                throw StepExecutionFailed::forCallbackFailure(
                    $this->manifest->pipelineId,
                    $this->manifest->currentStepIndex,
                    $stepClass,
                    $callbackException,
                    $exception,
                );
            }

            try {
                $this->firePipelineCallback($this->manifest->onCompleteCallback, $this->manifest->context);
            } catch (Throwable $callbackException) {
                // AC #12 queued: a throwing onComplete replaces the
                // originally-intended rethrow; wrap uniformly so
                // failed_jobs carries the StepExecutionFailed envelope
                // with originalStepException preserved.
                throw StepExecutionFailed::forCallbackFailure(
                    $this->manifest->pipelineId,
                    $this->manifest->currentStepIndex,
                    $stepClass,
                    $callbackException,
                    $exception,
                );
            }

            throw $exception;
        }

        $this->manifest->markStepCompleted($stepClass);
        $this->manifest->advanceStep();

        // AC #6: a successful step under SkipAndContinue clears any failure
        // recorded by a previously skipped step. No-op under StopImmediately /
        // StopAndCompensate because those paths never reach this success tail
        // with failure fields set.
        $this->manifest->failureException = null;
        $this->manifest->failedStepClass = null;
        $this->manifest->failedStepIndex = null;

        if ($this->manifest->currentStepIndex < count($this->manifest->stepClasses)) {
            $this->dispatchNextStep();

            return;
        }

        // Story 6.2 AC #1, #7: pipeline terminates on this wrapper (last step
        // completed). onSuccess fires first, then onComplete. Callback throws
        // mark the wrapper failed in Laravel's queue (acceptable per AC #12
        // queued-mode clause).
        $this->firePipelineCallback($this->manifest->onSuccessCallback, $this->manifest->context);
        $this->firePipelineCallback($this->manifest->onCompleteCallback, $this->manifest->context);
    }

    /**
     * Decide whether the step at the given index should be skipped based on its condition entry.
     *
     * Mirrors SyncExecutor::shouldSkipStep(). Returns false when no
     * condition is registered for the index; otherwise unwraps the
     * SerializableClosure and applies the `negated` flag. Called from
     * inside the surrounding try/catch so a throwing closure is logged
     * via Log::error('Pipeline step failed', ...) and rethrown for
     * Laravel's queue failure handling to fire.
     *
     * @param int $stepIndex The zero-based index of the step being evaluated.
     *
     * @return bool True when the step must be skipped, false when it should run.
     */
    private function shouldSkip(int $stepIndex): bool
    {
        $entry = $this->manifest->stepConditions[$stepIndex] ?? null;

        if ($entry === null) {
            return false;
        }

        $closure = $entry['closure']->getClosure();
        $result = (bool) $closure($this->manifest->context);
        $shouldRun = $entry['negated'] ? ! $result : $result;

        return ! $shouldRun;
    }

    /**
     * Invoke the step's handle() method with an in-process retry loop.
     *
     * Reads the per-step configuration from
     * `$this->manifest->stepConfigs[$this->manifest->currentStepIndex]`.
     * Fast path: when retry is null or zero, `app()->call([$job, 'handle'])`
     * runs once — zero retry-loop overhead when retry is unset. Retry path:
     * `retry + 1` attempts with `sleep($backoff)` between non-final attempts.
     * The final attempt's exception propagates to the outer catch where
     * FailStrategy branching takes over.
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
     * The `timeout` value from the config is intentionally NOT consulted
     * here; it is applied at dispatch time on the wrapper's public
     * `$timeout` property, which Laravel's worker reads via `pcntl_alarm()`.
     *
     * @param object $job The resolved step job instance (already has manifest injected when applicable).
     * @return void
     *
     * @throws Throwable The final attempt's exception when the retry loop exhausts.
     */
    private function invokeStepWithRetry(object $job): void
    {
        $config = $this->manifest->stepConfigs[$this->manifest->currentStepIndex]
            ?? ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

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
     * Dispatch the next PipelineStepJob wrapper, applying per-step config.
     *
     * Resolves `$this->manifest->stepConfigs[$this->manifest->currentStepIndex]`
     * which, by the time this helper is called, has been advanced by the
     * caller to point at the UPCOMING step's config index. Applies the same
     * branching logic as QueuedExecutor::dispatchFirstStep():
     *
     * - `sync === true` → `dispatch_sync()`: runs the next wrapper
     *   synchronously in the current worker's process; `handle()` does not
     *   return until the inline wrapper fully executes. Exceptions propagate
     *   synchronously. When a `timeout` is declared, it is still assigned on
     *   the wrapper (inert observationally because `dispatch_sync` does not
     *   install a `pcntl_alarm`) so test assertions observe the declared
     *   value on both branches symmetrically.
     * - `sync === false` with explicit queue / connection → the job is
     *   configured via `onQueue()` / `onConnection()` before `dispatch()`
     *   is called.
     * - `sync === false` with null queue / connection → no-op mutations.
     *
     * The `timeout` config key, when non-null, is assigned to the wrapper's
     * public `$timeout` property (inherited from Laravel's Queueable trait).
     * Laravel's queue worker reads the property at job pickup and calls
     * `pcntl_alarm($timeout)` before invoking `handle()`; on SIGALRM the
     * worker is killed and the wrapper lands in `failed_jobs`. The
     * wrapper's `$tries` remains locked to 1 regardless of the per-step
     * retry config (retry is delivered via the in-process loop in
     * `invokeStepWithRetry()`, not via Laravel's wrapper-level retry).
     *
     * @return void
     */
    private function dispatchNextStep(): void
    {
        $config = $this->manifest->stepConfigs[$this->manifest->currentStepIndex] ?? null;

        // Parallel-group positions carry a nested config shape and no
        // per-wrapper queue/connection/timeout: the outer wrapper's sole
        // responsibility is to detect the parallel shape in handle() and
        // fan out via dispatchParallelBatch(). Dispatch as a plain wrapper.
        if (is_array($config) && ($config['type'] ?? null) === 'parallel') {
            dispatch(new self($this->manifest));

            return;
        }

        $config = is_array($config)
            ? $config
            : ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

        if ((bool) ($config['sync'] ?? false)) {
            $job = new self($this->manifest);

            if (($config['timeout'] ?? null) !== null) {
                $job->timeout = $config['timeout'];
            }

            dispatch_sync($job);

            return;
        }

        $job = new self($this->manifest);

        if (($config['queue'] ?? null) !== null) {
            $job->onQueue($config['queue']);
        }

        if (($config['connection'] ?? null) !== null) {
            $job->onConnection($config['connection']);
        }

        if (($config['timeout'] ?? null) !== null) {
            $job->timeout = $config['timeout'];
        }

        dispatch($job);
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

        $jobs = [];

        foreach ($subStepClasses as $subIndex => $subStepClass) {
            /** @var PipelineManifest $clonedManifest */
            $clonedManifest = unserialize(serialize($this->manifest));

            $wrapper = new ParallelStepJob(
                manifest: $clonedManifest,
                groupIndex: $groupIndex,
                subStepIndex: $subIndex,
                stepClass: $subStepClass,
            );

            $config = $this->resolveParallelSubConfig($groupIndex, $subIndex);

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
        // the next step or firing the success tail. Placing advanceStep()
        // AFTER the hasFailures() branch preserves the "failed group stays
        // at its position" invariant that mirrors the sync path (failures
        // leave currentStepIndex pointing at the failing group for
        // observability / downstream diagnostics).
        $manifest->advanceStep();

        if ($manifest->currentStepIndex < count($manifest->stepClasses)) {
            $nextJob = new self($manifest);

            $nextConfig = $manifest->stepConfigs[$manifest->currentStepIndex] ?? null;

            if (is_array($nextConfig) && ! isset($nextConfig['type'])) {
                if (($nextConfig['queue'] ?? null) !== null) {
                    $nextJob->onQueue($nextConfig['queue']);
                }

                if (($nextConfig['connection'] ?? null) !== null) {
                    $nextJob->onConnection($nextConfig['connection']);
                }

                if (($nextConfig['timeout'] ?? null) !== null) {
                    $nextJob->timeout = $nextConfig['timeout'];
                }

                if ((bool) $nextConfig['sync']) {
                    dispatch_sync($nextJob);

                    return;
                }
            }

            dispatch($nextJob);

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
     * Resolve the per-sub-step config entry inside a parallel group.
     *
     * Reads $this->manifest->stepConfigs[$groupIndex]['configs'][$subIndex]
     * and falls back to the default null-config shape when missing or
     * malformed. Defensive against hand-built manifests that skip the
     * PipelineBuilder's nested-shape generation.
     *
     * @param int $groupIndex The outer position of the parallel group.
     * @param int $subIndex The zero-based declaration-order index of the sub-step.
     *
     * @return array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} The resolved per-sub-step configuration.
     */
    private function resolveParallelSubConfig(int $groupIndex, int $subIndex): array
    {
        $default = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];
        $groupEntry = $this->manifest->stepConfigs[$groupIndex] ?? null;

        if (! is_array($groupEntry) || ($groupEntry['type'] ?? null) !== 'parallel') {
            return $default;
        }

        /** @var array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}> $configs */
        $configs = $groupEntry['configs'];

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
