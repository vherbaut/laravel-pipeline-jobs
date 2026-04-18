<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
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

                    $this->executeNestedPipeline(
                        $manifest,
                        $stepIndex,
                        $innerSteps,
                        $nestedName,
                        $innerConditionsEntries,
                        $innerConfigsEntries,
                    );

                    continue;
                }

                /** @var array<int, string> $parallelClasses */
                $parallelClasses = $stepClass['classes'] ?? [];
                $this->executeParallelGroup($manifest, $stepIndex, $parallelClasses);

                continue;
            }

            try {
                if ($this->shouldSkipStep($manifest, $stepIndex)) {
                    $manifest->advanceStep();

                    continue;
                }

                $job = app()->make($stepClass);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                $this->fireHooks(
                    $manifest->beforeEachHooks,
                    StepDefinition::fromJobClass($stepClass),
                    $manifest->context,
                );

                $this->invokeStepWithRetry(
                    $job,
                    $manifest->stepConfigs[$stepIndex] ?? ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
                );

                $this->fireHooks(
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
                    $this->fireHooks(
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
                    $this->runCompensationChain($manifest);
                }

                // Story 6.2 AC #2, #11: pipeline-level onFailure fires AFTER
                // per-step onStepFailed (Story 6.1) AND AFTER compensation
                // (under StopAndCompensate) AND BEFORE the terminal rethrow.
                // Under SkipAndContinue this block is unreachable (AC #10).
                try {
                    $this->firePipelineCallback(
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
                    $this->firePipelineCallback($manifest->onCompleteCallback, $manifest->context);
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
        $this->firePipelineCallback($manifest->onSuccessCallback, $manifest->context);
        $this->firePipelineCallback($manifest->onCompleteCallback, $manifest->context);

        return $manifest->context;
    }

    /**
     * Invoke the step's handle() method with an in-process retry loop.
     *
     * Fast path: when the resolved retry is null or zero, the method calls
     * `app()->call([$job, 'handle'])` exactly once and returns — zero
     * retry-loop overhead when retry is unset. Retry path: when retry is a
     * positive integer, the method enters a loop of at most `retry + 1`
     * attempts (1 initial + `retry` retries); a successful invocation
     * returns immediately, a throw on a non-final attempt triggers
     * `sleep($backoff)` (when backoff > 0) and another attempt, and a throw
     * on the final attempt propagates to the caller.
     *
     * Instance-reuse contract: the step `$job` is resolved ONCE by the
     * caller (`app()->make($stepClass)`) before this helper runs; the SAME
     * instance receives every retry attempt. Instance-level state
     * (counters, accumulators, cached service handles) persists across
     * attempts. This differs from Laravel's native queue retry which
     * re-resolves per attempt because it crosses process boundaries; the
     * in-process retry here stays inside one PHP process and therefore
     * preserves the instance. Users relying on step-local state (e.g.
     * circuit breakers, partial-accumulation defense) should expect this
     * semantic.
     *
     * Hooks observe the step as a single logical unit: beforeEach fires
     * before this method is called, afterEach fires once after this method
     * returns successfully, and onStepFailed fires once (inside the outer
     * catch) only when the final attempt throws. Intermediate-attempt
     * failures do NOT fire onStepFailed.
     *
     * The `timeout` key of the config array is intentionally ignored in
     * synchronous mode; the class-level PHPDoc documents this inertness.
     *
     * @param object $job The resolved step job instance (already has manifest injected when applicable).
     * @param array{retry: ?int, backoff: ?int, timeout: ?int} $config Resolved per-step configuration entry; legacy three-key shapes degrade to no-retry.
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
     * Invoke a pipeline-level callback with the appropriate argument set.
     *
     * Null-guards on the callback slot (zero-overhead contract, AC #6);
     * unwraps the SerializableClosure via getClosure() and calls it with
     * $context alone for onSuccess/onComplete, or ($context, $exception)
     * for onFailure. A throw from the invoked closure propagates unchanged
     * (no silent swallow, architecture.md:395); caller sites handle the
     * sync/queued wrapping semantics (AC #12).
     *
     * Duplicated across SyncExecutor, PipelineStepJob, and RecordingExecutor
     * per Story 5.2 Design Decision #2 (three-site duplication over shared
     * helper for readability).
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
     * Decide whether the step at the given index should be skipped based on its condition entry.
     *
     * Returns false when no condition is registered for the index or when the
     * entry is the nested parallel-group shape (parallel sub-step conditions
     * are evaluated individually inside executeParallelGroup()). Otherwise
     * unwraps the SerializableClosure, evaluates it against the current
     * context, and applies the `negated` flag. A throwing closure propagates
     * so the surrounding catch block converts it to StepExecutionFailed.
     *
     * @param PipelineManifest $manifest The manifest carrying stepConditions and context.
     * @param int $stepIndex The zero-based index of the step being evaluated.
     *
     * @return bool True when the step must be skipped, false when it should run.
     */
    private function shouldSkipStep(PipelineManifest $manifest, int $stepIndex): bool
    {
        $entry = $manifest->stepConditions[$stepIndex] ?? null;

        if ($entry === null) {
            return false;
        }

        if (($entry['type'] ?? null) === 'parallel') {
            return false;
        }

        /** @var array{closure: SerializableClosure, negated: bool} $entry */
        $closure = $entry['closure']->getClosure();
        $result = (bool) $closure($manifest->context);
        $shouldRun = $entry['negated'] ? ! $result : $result;

        return ! $shouldRun;
    }

    /**
     * Execute a parallel step group's sub-steps sequentially in the current process.
     *
     * Synchronous parallelism is semantic, not concurrent: each sub-step
     * runs inline, receives the SAME live PipelineContext (context mutations
     * by earlier sub-steps are visible to later ones within the group —
     * users expecting isolation must run queued), and contributes to the
     * flat $manifest->completedSteps list by its own class name so reverse-
     * order compensation over a StopAndCompensate failure includes it. The
     * group advances the outer position exactly ONCE after all sub-steps
     * have been processed.
     *
     * Per-sub-step conditions (when()/unless()) are evaluated against the
     * live context. Skipped sub-steps do NOT fire beforeEach/afterEach, do
     * NOT record completion, and do NOT count as a success for the purposes
     * of clearing SkipAndContinue failure fields (mirrors the single-step
     * skip contract in shouldSkipStep()).
     *
     * Failure handling per FailStrategy (AC #9):
     * - StopImmediately: first sub-step failure aborts remaining siblings in
     *   this group. onStepFailed fires with the failing sub-step's
     *   StepDefinition; terminal onFailure / onComplete callbacks fire in
     *   the canonical order; StepExecutionFailed is thrown naming the
     *   failing sub-step.
     * - StopAndCompensate: identical to StopImmediately except the
     *   compensation chain over $completedSteps runs before the callback
     *   sequence (reversed over all completed steps including sub-steps
     *   completed earlier in this group).
     * - SkipAndContinue: the failed sub-step is logged, failure fields are
     *   cleared, remaining siblings continue to run, and any subsequent
     *   sub-step success resets the last-failure fields (the group's
     *   outer position still advances exactly once at the end). Matches
     *   the "best-effort parallel group" semantic of the AC.
     *
     * Per-sub-step queue/connection/timeout config is INERT in sync mode
     * (parity with the single-step sync path). Per-sub-step retry/backoff
     * runs via the shared invokeStepWithRetry() helper, identical to the
     * single-step call site.
     *
     * @param PipelineManifest $manifest The mutable manifest carrying context, completedSteps, and per-step conditions/configs.
     * @param int $groupIndex The outer position of the parallel group in the pipeline.
     * @param array<int, string> $subStepClasses Sub-step class-strings in declaration order.
     * @return void
     *
     * @throws StepExecutionFailed When a sub-step fails under StopImmediately or StopAndCompensate (or when a hook or callback re-throws).
     */
    private function executeParallelGroup(PipelineManifest $manifest, int $groupIndex, array $subStepClasses): void
    {
        $groupConditions = $manifest->stepConditions[$groupIndex] ?? null;
        $groupConfigs = $manifest->stepConfigs[$groupIndex] ?? null;

        /** @var array<int, array{closure: SerializableClosure, negated: bool}|null> $subConditions */
        $subConditions = (is_array($groupConditions) && ($groupConditions['type'] ?? null) === 'parallel')
            ? $groupConditions['entries']
            : [];

        /** @var array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}> $subConfigs */
        $subConfigs = (is_array($groupConfigs) && ($groupConfigs['type'] ?? null) === 'parallel')
            ? $groupConfigs['configs']
            : [];

        $this->executeParallelEntries($manifest, $groupIndex, $subStepClasses, $subConditions, $subConfigs);

        $manifest->advanceStep();
    }

    /**
     * Execute a list of parallel sub-steps in the current process without advancing the outer position.
     *
     * Extracted from executeParallelGroup() so the nested-pipeline path
     * (executeNestedPipeline()) can reuse the parallel sub-step body for a
     * parallel-inside-nested entry without double-advancing the outer
     * currentStepIndex (the nested group advances once at its own terminal).
     * Callers invoked from the outer execute() loop wrap this helper with
     * $manifest->advanceStep(); callers invoked from inside a nested group
     * do NOT advance, letting the enclosing nested group's single advance
     * at its terminal govern.
     *
     * @param PipelineManifest $manifest The mutable manifest carrying context, completedSteps, and hooks.
     * @param int $groupIndex The outer position of the enclosing group (used for observability on failure).
     * @param array<int, string> $subStepClasses Sub-step class-strings in declaration order.
     * @param array<int, array{closure: SerializableClosure, negated: bool}|null> $subConditions Per-sub-step condition entries aligned with $subStepClasses; null means unconditional.
     * @param array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}> $subConfigs Per-sub-step resolved configs aligned with $subStepClasses.
     * @return void
     *
     * @throws StepExecutionFailed When a sub-step fails under StopImmediately or StopAndCompensate (or when a hook or callback re-throws).
     */
    private function executeParallelEntries(
        PipelineManifest $manifest,
        int $groupIndex,
        array $subStepClasses,
        array $subConditions,
        array $subConfigs,
    ): void {
        $defaultConfig = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

        foreach ($subStepClasses as $subIndex => $subStepClass) {
            // Evaluate the sub-step condition OUTSIDE the outer try so a
            // throwing condition closure is not misattributed to the
            // sub-step's handle() via the sub-step catch block (the sub-step
            // never ran). Condition-throws still surface as
            // StepExecutionFailed::forStep with the sub-step class as the
            // location so operators get a clear trail.
            try {
                $shouldSkip = $this->shouldSkipParallelSubStep($subConditions[$subIndex] ?? null, $manifest->context);
            } catch (Throwable $conditionException) {
                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $groupIndex,
                    $subStepClass,
                    $conditionException,
                );
            }

            if ($shouldSkip) {
                continue;
            }

            try {
                $job = app()->make($subStepClass);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                $this->fireHooks(
                    $manifest->beforeEachHooks,
                    StepDefinition::fromJobClass($subStepClass),
                    $manifest->context,
                );

                $this->invokeStepWithRetry($job, $subConfigs[$subIndex] ?? $defaultConfig);

                $this->fireHooks(
                    $manifest->afterEachHooks,
                    StepDefinition::fromJobClass($subStepClass),
                    $manifest->context,
                );

                $manifest->markStepCompleted($subStepClass);

                // Under SkipAndContinue, a sub-step success clears any failure
                // recorded by an earlier sub-step in the same group (AC #9).
                $manifest->failureException = null;
                $manifest->failedStepClass = null;
                $manifest->failedStepIndex = null;
            } catch (Throwable $subException) {
                $manifest->failureException = $subException;
                $manifest->failedStepClass = $subStepClass;
                $manifest->failedStepIndex = $groupIndex;

                try {
                    $this->fireHooks(
                        $manifest->onStepFailedHooks,
                        StepDefinition::fromJobClass($subStepClass),
                        $manifest->context,
                        $subException,
                    );
                } catch (Throwable $hookException) {
                    throw StepExecutionFailed::forStep(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $subStepClass,
                        $hookException,
                    );
                }

                if ($manifest->failStrategy === FailStrategy::SkipAndContinue) {
                    Log::warning('Pipeline parallel sub-step skipped under SkipAndContinue', [
                        'pipelineId' => $manifest->pipelineId,
                        'groupIndex' => $groupIndex,
                        'subStepClass' => $subStepClass,
                        'subStepIndex' => $subIndex,
                        'exception' => $subException->getMessage(),
                    ]);

                    $manifest->failureException = null;

                    continue;
                }

                if ($manifest->failStrategy === FailStrategy::StopAndCompensate) {
                    // Wrap the compensation chain so a throwing compensation
                    // does NOT skip over the onFailure / onComplete sequence
                    // below. The original sub-step exception stays the
                    // canonical cause; the compensation failure is logged
                    // for operator diagnostics.
                    try {
                        $this->runCompensationChain($manifest);
                    } catch (Throwable $compensationException) {
                        Log::error('Pipeline compensation chain failed during parallel group rollback', [
                            'pipelineId' => $manifest->pipelineId,
                            'groupIndex' => $groupIndex,
                            'subStepClass' => $subStepClass,
                            'compensationException' => $compensationException->getMessage(),
                            'originalException' => $subException->getMessage(),
                        ]);
                    }
                }

                try {
                    $this->firePipelineCallback(
                        $manifest->onFailureCallback,
                        $manifest->context,
                        $subException,
                    );
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $subStepClass,
                        $callbackException,
                        $subException,
                    );
                }

                try {
                    $this->firePipelineCallback($manifest->onCompleteCallback, $manifest->context);
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $subStepClass,
                        $callbackException,
                        $subException,
                    );
                }

                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $manifest->currentStepIndex,
                    $subStepClass,
                    $subException,
                );
            }
        }
    }

    /**
     * Execute a nested-pipeline group's inner steps sequentially with a shared manifest.
     *
     * Inner steps share the OUTER PipelineContext instance (mutations by an
     * earlier inner step are visible to later inner steps AND to outer steps
     * after the group completes) and contribute to the flat
     * $manifest->completedSteps list by their own class names (reverse-order
     * compensation over StopAndCompensate spans inner + outer entries).
     * Parallel sub-groups inside the nested pipeline fan out sequentially in
     * sync mode via the same executeParallelEntries() path used for outer
     * parallel positions. Nested-nested entries recurse through this same
     * method.
     *
     * Per-inner-step conditions (when()/unless()) are evaluated against the
     * live context via shouldSkipNestedEntry(). Skipped inner steps do NOT
     * fire beforeEach/afterEach, do NOT record completion, and do NOT clear
     * SkipAndContinue failure fields.
     *
     * OUTER pipeline hooks (beforeEachHooks / afterEachHooks /
     * onStepFailedHooks) fire per inner step (each inner step IS a step from
     * the hook contract's perspective — mirrors the parallel-group decision
     * from Story 8.1). The INNER pipeline's own hook arrays, if any, are
     * IGNORED (the inner PipelineDefinition is structurally present on the
     * NestedPipeline wrapper but its hook/callback slots are not consulted).
     *
     * Pipeline-level OUTER callbacks (onSuccess / onFailure / onComplete)
     * fire once at the OUTER terminal. The inner pipeline's own callbacks
     * are IGNORED.
     *
     * Per-inner-step queue/connection/timeout config is INERT in sync mode
     * (parity with the single-step and parallel paths). Per-inner-step
     * retry/backoff runs via invokeStepWithRetry(), identical to the
     * single-step call site.
     *
     * Failure handling per FailStrategy (AC #9):
     * - StopImmediately: first inner failure aborts remaining inner steps in
     *   THIS nested group. onStepFailed fires with the failing inner step's
     *   StepDefinition; outer failedStepClass is set to the inner class and
     *   outer failedStepIndex is set to the ENCLOSING nested group's outer
     *   position; pipeline callbacks fire; StepExecutionFailed is thrown.
     * - StopAndCompensate: identical to StopImmediately except the
     *   compensation chain over $completedSteps runs before the callback
     *   sequence (reverse-order over all completed steps, inner included).
     * - SkipAndContinue: the failed inner step is logged, failure fields are
     *   cleared, remaining inner steps continue, and any subsequent inner
     *   success resets the last-failure fields. The group's outer position
     *   still advances exactly once at the end (symmetric with parallel).
     *
     * The OUTER pipeline's FailStrategy governs. The inner
     * PipelineDefinition's own failStrategy field is IGNORED once wrapped as
     * a NestedPipeline.
     *
     * @param PipelineManifest $manifest The mutable manifest carrying context, completedSteps, and per-step conditions/configs.
     * @param int $groupIndex The outer position of the nested group in the pipeline (used for observability on failure).
     * @param array<int, string|array<string, mixed>> $innerSteps Inner-step entries in declaration order: class-string, parallel shape, or nested shape.
     * @param string|null $nestedName Optional user-visible sub-pipeline name for observability (currently surfaced via log context only).
     * @param array<int, array<string, mixed>|null> $innerConditionsEntries Per-inner-position condition entries aligned with $innerSteps; each entry is null, a flat condition shape, a parallel-sub shape, or a nested-sub shape.
     * @param array<int, array<string, mixed>> $innerConfigsEntries Per-inner-position resolved configs aligned with $innerSteps; each entry is a flat config shape, a parallel-sub shape, or a nested-sub shape.
     * @return void
     *
     * @throws StepExecutionFailed When an inner step fails under StopImmediately or StopAndCompensate (or when a hook or callback re-throws).
     */
    private function executeNestedPipeline(
        PipelineManifest $manifest,
        int $groupIndex,
        array $innerSteps,
        ?string $nestedName,
        array $innerConditionsEntries,
        array $innerConfigsEntries,
    ): void {
        $defaultConfig = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

        foreach ($innerSteps as $subIndex => $entry) {
            $conditionEntry = $innerConditionsEntries[$subIndex] ?? null;
            $configEntry = $innerConfigsEntries[$subIndex] ?? null;

            if (is_array($entry)) {
                $entryType = $entry['type'] ?? null;

                if ($entryType === 'parallel') {
                    /** @var array<int, string> $subSubClasses */
                    $subSubClasses = $entry['classes'] ?? [];

                    /** @var array<int, array<string, mixed>|null> $subSubConditions */
                    $subSubConditions = (is_array($conditionEntry) && ($conditionEntry['type'] ?? null) === 'parallel')
                        ? $conditionEntry['entries']
                        : [];

                    /** @var array<int, array<string, mixed>> $subSubConfigs */
                    $subSubConfigs = (is_array($configEntry) && ($configEntry['type'] ?? null) === 'parallel')
                        ? $configEntry['configs']
                        : [];

                    $this->executeParallelEntries(
                        $manifest,
                        $groupIndex,
                        $subSubClasses,
                        $subSubConditions,
                        $subSubConfigs,
                    );

                    continue;
                }

                if ($entryType === 'nested') {
                    /** @var array<int, string|array<string, mixed>> $innerInnerSteps */
                    $innerInnerSteps = $entry['steps'] ?? [];

                    /** @var array<int, array<string, mixed>|null> $innerInnerConditions */
                    $innerInnerConditions = (is_array($conditionEntry) && ($conditionEntry['type'] ?? null) === 'nested')
                        ? $conditionEntry['entries']
                        : [];

                    /** @var array<int, array<string, mixed>> $innerInnerConfigs */
                    $innerInnerConfigs = (is_array($configEntry) && ($configEntry['type'] ?? null) === 'nested')
                        ? $configEntry['configs']
                        : [];

                    $this->executeNestedPipeline(
                        $manifest,
                        $groupIndex,
                        $innerInnerSteps,
                        $entry['name'] ?? null,
                        $innerInnerConditions,
                        $innerInnerConfigs,
                    );

                    continue;
                }

                // Unknown shape; treat defensively as a logic error so we surface it rather than silently skip.
                throw new LogicException(
                    'SyncExecutor::executeNestedPipeline encountered unknown inner-entry type '
                    .var_export($entryType, true).' at outer position '.$groupIndex.', inner position '.$subIndex.'.',
                );
            }

            // Flat inner step body (string class-name).
            try {
                /** @var array{closure: SerializableClosure, negated: bool}|null $flatConditionEntry */
                $flatConditionEntry = (is_array($conditionEntry) && ! isset($conditionEntry['type']))
                    ? $conditionEntry
                    : null;
                // Legacy defensive fallback: if the condition entry is itself a group shape we ignore it here.

                $shouldSkip = $this->shouldSkipNestedFlatEntry($flatConditionEntry, $manifest->context);
            } catch (Throwable $conditionException) {
                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $groupIndex,
                    $entry,
                    $conditionException,
                );
            }

            if ($shouldSkip) {
                continue;
            }

            try {
                $job = app()->make($entry);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                $this->fireHooks(
                    $manifest->beforeEachHooks,
                    StepDefinition::fromJobClass($entry),
                    $manifest->context,
                );

                /** @var array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} $flatConfig */
                $flatConfig = (is_array($configEntry) && ! isset($configEntry['type']))
                    ? $configEntry
                    : $defaultConfig;

                $this->invokeStepWithRetry($job, $flatConfig);

                $this->fireHooks(
                    $manifest->afterEachHooks,
                    StepDefinition::fromJobClass($entry),
                    $manifest->context,
                );

                $manifest->markStepCompleted($entry);

                $manifest->failureException = null;
                $manifest->failedStepClass = null;
                $manifest->failedStepIndex = null;
            } catch (Throwable $innerException) {
                // "Collapse double-wrapping" (deferred-work.md:25): if an inner
                // step itself runs another pipeline that threw
                // StepExecutionFailed, unwrap to the underlying step exception
                // so the outer frame wraps ONCE.
                $cause = $innerException instanceof StepExecutionFailed
                    ? ($innerException->getPrevious() ?? $innerException)
                    : $innerException;

                $manifest->failureException = $cause;
                $manifest->failedStepClass = $entry;
                $manifest->failedStepIndex = $groupIndex;

                try {
                    $this->fireHooks(
                        $manifest->onStepFailedHooks,
                        StepDefinition::fromJobClass($entry),
                        $manifest->context,
                        $cause,
                    );
                } catch (Throwable $hookException) {
                    throw StepExecutionFailed::forStep(
                        $manifest->pipelineId,
                        $groupIndex,
                        $entry,
                        $hookException,
                    );
                }

                if ($manifest->failStrategy === FailStrategy::SkipAndContinue) {
                    Log::warning('Pipeline nested inner step skipped under SkipAndContinue', [
                        'pipelineId' => $manifest->pipelineId,
                        'groupIndex' => $groupIndex,
                        'nestedName' => $nestedName,
                        'innerStepClass' => $entry,
                        'innerStepIndex' => $subIndex,
                        'exception' => $cause->getMessage(),
                    ]);

                    $manifest->failureException = null;

                    continue;
                }

                if ($manifest->failStrategy === FailStrategy::StopAndCompensate) {
                    try {
                        $this->runCompensationChain($manifest);
                    } catch (Throwable $compensationException) {
                        Log::error('Pipeline compensation chain failed during nested group rollback', [
                            'pipelineId' => $manifest->pipelineId,
                            'groupIndex' => $groupIndex,
                            'nestedName' => $nestedName,
                            'innerStepClass' => $entry,
                            'compensationException' => $compensationException->getMessage(),
                            'originalException' => $cause->getMessage(),
                        ]);
                    }
                }

                try {
                    $this->firePipelineCallback(
                        $manifest->onFailureCallback,
                        $manifest->context,
                        $cause,
                    );
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $groupIndex,
                        $entry,
                        $callbackException,
                        $cause,
                    );
                }

                try {
                    $this->firePipelineCallback($manifest->onCompleteCallback, $manifest->context);
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $groupIndex,
                        $entry,
                        $callbackException,
                        $cause,
                    );
                }

                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $groupIndex,
                    $entry,
                    $cause,
                );
            }
        }

        $manifest->advanceStep();
    }

    /**
     * Decide whether a nested-pipeline inner flat step should be skipped given its condition entry.
     *
     * Mirrors shouldSkipStep() but operates on the entry shape resolved
     * inside a nested group (a flat entry or null). The null placeholder in
     * the entries array signals "no condition" (always run), matching the
     * alignment invariant from buildNestedStepConditionsPayload().
     *
     * @param array{closure: SerializableClosure, negated: bool}|null $entry The inner step's condition entry or null when none registered.
     * @param PipelineContext|null $context The live pipeline context at evaluation time.
     *
     * @return bool True when the inner step must be skipped, false when it should run.
     */
    private function shouldSkipNestedFlatEntry(?array $entry, ?PipelineContext $context): bool
    {
        if ($entry === null) {
            return false;
        }

        $closure = $entry['closure']->getClosure();
        $result = (bool) $closure($context);
        $shouldRun = $entry['negated'] ? ! $result : $result;

        return ! $shouldRun;
    }

    /**
     * Decide whether a parallel sub-step should be skipped given its condition entry.
     *
     * Mirrors shouldSkipStep() but operates on the inner entry shape
     * carried inside a parallel group (a flat entry or null), rather than
     * reading from $manifest->stepConditions. The null placeholder in the
     * entries array signals "no condition" (always run), matching the
     * alignment invariant from buildStepConditions().
     *
     * @param array{closure: SerializableClosure, negated: bool}|null $entry The sub-step's condition entry or null when none registered.
     * @param PipelineContext|null $context The live pipeline context at evaluation time.
     *
     * @return bool True when the sub-step must be skipped, false when it should run.
     */
    private function shouldSkipParallelSubStep(?array $entry, ?PipelineContext $context): bool
    {
        if ($entry === null) {
            return false;
        }

        // Closure resolution + invocation may throw (SerializableClosure
        // signature errors after app.key rotation, user closure raising).
        // Let the throw propagate so the caller attributes it cleanly as a
        // condition failure rather than a sub-step failure.
        $closure = $entry['closure']->getClosure();
        $result = (bool) $closure($context);
        $shouldRun = $entry['negated'] ? ! $result : $result;

        return ! $shouldRun;
    }

    /**
     * Invoke a hook array in registration order with the appropriate arguments.
     *
     * Unwraps each SerializableClosure via getClosure() and calls it with
     * either ($step, $context) for beforeEach/afterEach hooks (exception is
     * null) or ($step, $context, $exception) for onStepFailed hooks.
     * Hook exceptions propagate on first throw: the loop aborts and
     * subsequent hooks in the array are NOT invoked. This matches the
     * Story 6.1 no-silent-swallow contract (AC #6, AC #7).
     *
     * Zero-overhead when unused: if $hooks is an empty array, the foreach
     * body never executes and no SerializableClosure unwrap occurs.
     *
     * Duplicated across SyncExecutor, PipelineStepJob, and RecordingExecutor
     * per Story 5.2 Design Decision #2 (three-site duplication preferred
     * over a shared helper for readability).
     *
     * @param array<int, SerializableClosure> $hooks Ordered list of wrapped hook closures to invoke.
     * @param StepDefinition $step Minimal snapshot of the currently executing step (jobClass only).
     * @param PipelineContext|null $context The live pipeline context, or null when no context was sent.
     * @param Throwable|null $exception The caught throwable when firing onStepFailed hooks; null for beforeEach/afterEach.
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
     * Run compensation jobs for every completed step in reverse order.
     *
     * Only invoked when $manifest->failStrategy === FailStrategy::StopAndCompensate.
     * Reads the ordered list of completed steps from $manifest->completedSteps,
     * reverses it, and for each completed step whose class has a compensation
     * mapping in $manifest->compensationMapping, resolves the compensation via
     * the container and invokes it through the CompensableJob-or-trait bridge:
     *
     * - If the compensation implements CompensableJob, calls compensate($context).
     * - Otherwise, injects the manifest into a pipelineManifest property when
     *   present, then calls handle() via the container (Story 3.3 pattern).
     *
     * Compensation is best-effort: a throwable from one compensation is silently
     * swallowed so the chain continues with the next entry. Logging and event
     * emission on compensation failure are deferred to Story 5.3 (NFR6).
     *
     * @param PipelineManifest $manifest The manifest carrying completedSteps, compensationMapping, and context.
     *
     * @return void
     */
    private function runCompensationChain(PipelineManifest $manifest): void
    {
        if ($manifest->compensationMapping === []) {
            return;
        }

        $reversedCompleted = array_reverse($manifest->completedSteps);
        $failureContext = FailureContext::fromManifest($manifest);

        foreach ($reversedCompleted as $completedStep) {
            if (! isset($manifest->compensationMapping[$completedStep])) {
                continue;
            }

            $compensationClass = $manifest->compensationMapping[$completedStep];

            try {
                $job = app()->make($compensationClass);

                if ($job instanceof CompensableJob) {
                    $this->invokeCompensate($job, $manifest->context, $failureContext);

                    continue;
                }

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                app()->call([$job, 'handle']);
            } catch (Throwable $compensationException) {
                $this->reportCompensationFailure(
                    $manifest,
                    $compensationClass,
                    $manifest->failureException,
                    $compensationException,
                );
                // Best-effort: compensation failures do not abort the chain.
            }
        }
    }

    /**
     * Invoke a CompensableJob's compensate() method, passing the FailureContext when the implementation accepts it.
     *
     * The CompensableJob interface declares a single-argument signature
     * (PipelineContext only); implementations may widen to two arguments to
     * opt into the Story 5.3 failure-context feature. Reflection is used to
     * detect both the parameter count and the second parameter's declared
     * type so the executor only passes the extra argument when the
     * implementation actually accepts a FailureContext. Signatures that
     * require more than two parameters are rejected at invocation time.
     *
     * @param CompensableJob $job The compensation job instance resolved from the container.
     * @param PipelineContext|null $context The pipeline context present at the failure point (may be null for context-less pipelines).
     * @param FailureContext|null $failure The failure-context snapshot, or null when no failure was recorded on the manifest.
     * @return void
     *
     * @throws LogicException When the compensate() signature declares more than two required parameters.
     */
    private function invokeCompensate(CompensableJob $job, ?PipelineContext $context, ?FailureContext $failure): void
    {
        $method = new ReflectionMethod($job, 'compensate');

        if ($method->getNumberOfRequiredParameters() > 2) {
            throw new LogicException(sprintf(
                'Compensation class [%s] declares compensate() with more than two required parameters; the executor only provides $context and $failure.',
                $job::class,
            ));
        }

        $args = self::compensateAcceptsFailureContext($method) ? [$context, $failure] : [$context];
        $method->invoke($job, ...$args);
    }

    /**
     * Decide whether a compensate() reflection method accepts a FailureContext as its second argument.
     *
     * Returns false for single-parameter signatures and for two-parameter
     * signatures whose second parameter type cannot be satisfied by a
     * FailureContext instance (incompatible class type, scalar type, or
     * intersection type that includes interfaces FailureContext does not
     * implement). Untyped, mixed, object, FailureContext, or compatible
     * supertype parameters all return true.
     *
     * @param ReflectionMethod $method The reflected compensate() method.
     * @return bool True when a FailureContext instance can be safely passed as the second argument.
     */
    private static function compensateAcceptsFailureContext(ReflectionMethod $method): bool
    {
        if ($method->getNumberOfParameters() < 2) {
            return false;
        }

        $type = $method->getParameters()[1]->getType();

        if ($type === null) {
            return true;
        }

        return self::typeAcceptsFailureContext($type);
    }

    /**
     * Recursive type-compatibility probe for compensateAcceptsFailureContext().
     *
     * @param ReflectionType $type A reflected parameter type (named, union, or intersection).
     * @return bool True when a FailureContext instance satisfies the declared type.
     */
    private static function typeAcceptsFailureContext(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return $type->getName() === 'mixed' || $type->getName() === 'object';
            }

            $name = $type->getName();

            return $name === FailureContext::class || is_subclass_of(FailureContext::class, $name);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if (self::typeAcceptsFailureContext($inner)) {
                    return true;
                }
            }

            return false;
        }

        // ReflectionIntersectionType: FailureContext is final and implements
        // no interfaces, so no intersection of types can be satisfied by it.
        return false;
    }

    /**
     * Emit the NFR6 observability pair for a compensation failure.
     *
     * Writes a structured `Log::error('Pipeline compensation failed', [...])`
     * line and dispatches a `CompensationFailed` event carrying the pipeline
     * identifier, the compensation class, the failing step class, and both
     * exceptions. Invoked from inside `runCompensationChain()` per-iteration
     * catch blocks; does not abort the chain (sync best-effort semantics).
     *
     * @param PipelineManifest $manifest The manifest carrying pipelineId and failedStepClass.
     * @param string $compensationClass Fully qualified class name of the compensation job that threw.
     * @param Throwable|null $originalException Throwable raised by the failing step, or null when no failure was recorded.
     * @param Throwable $compensationException Throwable raised by the compensation job itself.
     * @return void
     */
    private function reportCompensationFailure(
        PipelineManifest $manifest,
        string $compensationClass,
        ?Throwable $originalException,
        Throwable $compensationException,
    ): void {
        Log::error('Pipeline compensation failed', [
            'pipelineId' => $manifest->pipelineId,
            'compensationClass' => $compensationClass,
            'failedStepClass' => $manifest->failedStepClass,
            'compensationException' => $compensationException->getMessage(),
        ]);

        Event::dispatch(new CompensationFailedEvent(
            pipelineId: $manifest->pipelineId,
            compensationClass: $compensationClass,
            failedStepClass: $manifest->failedStepClass,
            originalException: $originalException,
            compensationException: $compensationException,
        ));
    }
}
