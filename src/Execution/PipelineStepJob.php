<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
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

            app()->call([$job, 'handle']);

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
     * Dispatch the next PipelineStepJob wrapper, applying per-step config.
     *
     * Resolves `$this->manifest->stepConfigs[$this->manifest->currentStepIndex]`
     * which, by the time this helper is called, has been advanced by the
     * caller to point at the UPCOMING step's config index. Applies the same
     * three-branch logic as QueuedExecutor::dispatchFirstStep():
     *
     * - `sync === true` → `dispatch_sync()`: runs the next wrapper
     *   synchronously in the current worker's process; `handle()` does not
     *   return until the inline wrapper fully executes. Exceptions propagate
     *   synchronously.
     * - `sync === false` with explicit queue / connection → the job is
     *   configured via `onQueue()` / `onConnection()` before `dispatch()`
     *   is called, so the configuration survives any exception raised
     *   before dispatch is issued.
     * - `sync === false` with null queue / connection → no-op mutations.
     *
     * Factored from three call sites (success tail, conditional-skip tail,
     * SkipAndContinue tail). The dispatch branching is not a short
     * one-liner, so factoring reduces maintenance burden when future
     * stories extend the per-step config surface.
     *
     * @return void
     */
    private function dispatchNextStep(): void
    {
        $config = $this->manifest->stepConfigs[$this->manifest->currentStepIndex]
            ?? ['queue' => null, 'connection' => null, 'sync' => false];

        if ((bool) $config['sync']) {
            dispatch_sync(new self($this->manifest));

            return;
        }

        $job = new self($this->manifest);

        if ($config['queue'] !== null) {
            $job->onQueue($config['queue']);
        }

        if ($config['connection'] !== null) {
            $job->onConnection($config['connection']);
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
