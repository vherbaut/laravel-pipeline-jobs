<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Shared;

use Laravel\SerializableClosure\SerializableClosure;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Shared invocation helpers for pipeline steps, hooks, and pipeline-level callbacks.
 *
 * Consolidates the retry loop, hook firing, and callback firing that were
 * previously duplicated across SyncExecutor, PipelineStepJob, ParallelStepJob,
 * and RecordingExecutor (Story 5.2 Design Decision #2). Every method is
 * static and stateless so the helper survives queue serialization boundaries
 * by never carrying instance state.
 *
 * @internal
 */
final class StepInvoker
{
    /**
     * Invoke a step job through the strategy-aware dispatcher with an in-process retry loop.
     *
     * Fast path when the config declares no retry (null or 0): calls
     * `StepInvocationDispatcher::call($job, $context)` once and returns.
     * Retry path runs up to `retry + 1` attempts with `sleep($backoff)`
     * between non-final attempts. The final attempt's exception propagates
     * to the caller.
     *
     * The dispatcher routes the call to one of three contracts based on the
     * resolved class shape (default `handle()`, middleware `handle($passable,
     * Closure $next)`, or invokable `__invoke()`); see
     * `StepInvocationDispatcher::detect()` for selection logic.
     *
     * The `timeout` key of the config is intentionally NOT consulted here;
     * it is applied at dispatch time on the queue wrapper's public
     * `$timeout` property and read by Laravel's worker via `pcntl_alarm()`.
     *
     * @param object $job The resolved step job instance (already has manifest injected when applicable).
     * @param array{queue?: ?string, connection?: ?string, sync?: bool, retry?: ?int, backoff?: ?int, timeout?: ?int} $config The pre-resolved per-step config; retry/backoff drive the loop.
     * @param PipelineContext|null $context The live pipeline context routed to middleware-shape and Action-shape steps; null when the pipeline was dispatched without ->send().
     * @return void
     *
     * @throws Throwable The final attempt's exception when the retry loop exhausts.
     */
    public static function invokeWithRetry(object $job, array $config, ?PipelineContext $context = null): void
    {
        $retry = $config['retry'] ?? null;

        if ($retry === null || $retry === 0) {
            StepInvocationDispatcher::call($job, $context);

            return;
        }

        $backoff = $config['backoff'] ?? 0;
        $maxAttempts = $retry + 1;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                StepInvocationDispatcher::call($job, $context);

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
     * Fire a hook array in registration order with the appropriate arguments.
     *
     * Unwraps each SerializableClosure via `getClosure()` and calls it with
     * `($step, $context)` for beforeEach/afterEach or `($step, $context, $exception)`
     * for onStepFailed. Hook exceptions propagate on first throw: the loop
     * aborts and subsequent hooks are NOT invoked (no silent swallow).
     *
     * Zero-overhead when unused: an empty `$hooks` array skips the loop body
     * entirely and no SerializableClosure unwrap occurs.
     *
     * @param array<int, SerializableClosure> $hooks Ordered list of wrapped hook closures to invoke.
     * @param StepDefinition $step Minimal snapshot of the currently executing step (jobClass only).
     * @param PipelineContext|null $context The live pipeline context, or null when no context was sent.
     * @param Throwable|null $exception The caught throwable when firing onStepFailed hooks; null for beforeEach/afterEach.
     * @return void
     */
    public static function fireHooks(array $hooks, StepDefinition $step, ?PipelineContext $context, ?Throwable $exception = null): void
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
     * Invoke a pipeline-level callback (onSuccess/onFailure/onComplete) with the appropriate arguments.
     *
     * Null-guards on the callback slot as a zero-overhead fast path. When a
     * callback is present, unwraps the SerializableClosure via `getClosure()`
     * and invokes it with `($context)` for onSuccess/onComplete or
     * `($context, $exception)` for onFailure. A throw from the invoked
     * closure propagates unchanged; caller sites decide how to wrap the
     * failure semantics (sync rethrow vs. queued worker failure).
     *
     * @param SerializableClosure|null $callback The wrapped pipeline-level callback, or null when not registered.
     * @param PipelineContext|null $context The live pipeline context at firing time (may be null).
     * @param Throwable|null $exception The caught throwable for onFailure; null for onSuccess/onComplete.
     * @return void
     */
    public static function firePipelineCallback(
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
}
