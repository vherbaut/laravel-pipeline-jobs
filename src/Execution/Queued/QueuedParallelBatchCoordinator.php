<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Queued;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\CompensationStepJob;
use Vherbaut\LaravelPipelineJobs\Execution\ParallelContextMerger;
use Vherbaut\LaravelPipelineJobs\Execution\ParallelStepJob;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;

/**
 * Queued parallel-group fan-out / fan-in coordinator.
 *
 * Builds one {@see ParallelStepJob} wrapper per declared sub-step, each
 * carrying its own deep-clone of the manifest (and therefore its own
 * PipelineContext clone) so sub-steps run with no shared mutable state
 * during the batch window. Applies per-sub-step queue / connection / timeout
 * overrides resolved from the nested `$stepConfigs[$groupIndex]['configs']`
 * shape before pushing each wrapper into the batch.
 *
 * The batch is configured with `->allowFailures()` + `->finally($closure)`
 * so siblings run to completion under any fail strategy; FailStrategy
 * branching happens in the fan-in continuation ({@see self::finalize()}).
 * `finally` (not `then`) is required because `recordFailedJob` does not
 * decrement pending_jobs under allowFailures(), so a then-based fan-in
 * would hang whenever any sub-step fails.
 *
 * Fan-in recovers per-sub-step final contexts from the Laravel cache
 * (written by {@see ParallelStepJob}), merges them via
 * {@see ParallelContextMerger::merge()}, clears the cache entries, then
 * applies FailStrategy branching (continue, compensate, or terminate).
 *
 * @internal
 */
final class QueuedParallelBatchCoordinator
{
    /**
     * Fan out one parallel group as a Bus::batch and register the fan-in callback.
     *
     * @param PipelineManifest $manifest The manifest at the parallel-group position (cursor-or-outer).
     * @param int $groupIndex The outer pipeline position of the parallel group.
     * @param array<int, string> $subStepClasses Sub-step class-strings in declaration order.
     * @return void
     *
     * @throws Throwable When the underlying Bus::batch dispatch itself throws.
     */
    public static function dispatch(PipelineManifest $manifest, int $groupIndex, array $subStepClasses): void
    {
        $baselineContext = $manifest->context === null
            ? null
            : unserialize(serialize($manifest->context));

        // Resolve the effective parallel-shape config entry once. For a top-level
        // parallel group this is stepConfigs[$groupIndex]. For a parallel group
        // nested inside a NestedPipeline the enclosing entry is a nested shape
        // whose inner configs[$innerIndex] holds the parallel shape; stepConfigAt()
        // navigates the cursor path to fetch it. Missing or malformed entries
        // degrade to null so the downstream lookup falls back to the default
        // null-config shape.
        $parallelShape = $manifest->nestedCursor !== []
            ? $manifest->stepConfigAt($manifest->nestedCursor)
            : ($manifest->stepConfigs[$groupIndex] ?? null);

        if (! is_array($parallelShape) || ($parallelShape['type'] ?? null) !== 'parallel') {
            $parallelShape = null;
        }

        $jobs = [];

        foreach ($subStepClasses as $subIndex => $subStepClass) {
            // Inject the resolved parallel shape at the cloned manifest's
            // $groupIndex slot so ParallelStepJob::resolveSubStepConfig() finds
            // the per-sub-step config entries under
            // stepConfigs[$groupIndex]['configs'][$subIndex] even for
            // parallel-inside-nested, where the real nested tree carries it at
            // stepConfigs[cursor[0]]['configs'][cursor[1]]. Re-keying goes
            // through withRekeyedStepConfig() because stepConfigs is readonly
            // and can only be assigned via __unserialize.
            if ($parallelShape !== null && $manifest->nestedCursor !== []) {
                $clonedManifest = $manifest->withRekeyedStepConfig($groupIndex, $parallelShape);
            } else {
                /** @var PipelineManifest $clonedManifest */
                $clonedManifest = unserialize(serialize($manifest));
            }

            $wrapper = new ParallelStepJob(
                manifest: $clonedManifest,
                groupIndex: $groupIndex,
                subStepIndex: $subIndex,
                stepClass: $subStepClass,
            );

            $config = self::resolveSubConfig($parallelShape, $subIndex);

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

        $pipelineId = $manifest->pipelineId;
        $outerManifestSnapshot = unserialize(serialize($manifest));
        $subCount = count($subStepClasses);

        // Fan-in continuation: registered as `->finally()` so it fires once all
        // sub-step jobs have RUN (pending - failed === 0), not only when they
        // all succeeded. Laravel's `->then()` only fires when pending_jobs hits
        // 0; recordFailedJob increments failed_jobs WITHOUT decrementing
        // pending_jobs, so any failure under allowFailures() would leave a
        // then-based fan-in stranded forever.
        $finalizeCallback = new SerializableClosure(function (Batch $batch) use (
            $outerManifestSnapshot,
            $baselineContext,
            $pipelineId,
            $groupIndex,
            $subStepClasses,
            $subCount,
        ): void {
            // Routed through the PipelineStepJob static stub so SerializableClosures
            // already on the queue (captured before this refactor) keep resolving.
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

        // Observability hook: log the first batch-job failure so operators get
        // an early signal before the fan-in aggregate pass. `catch` fires once
        // on the first failure regardless of allowFailures(); it is additive
        // observability, not control flow.
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
     * Recovers per-sub-step final contexts from the Laravel cache (written by
     * {@see ParallelStepJob::handle()}), merges them into the baseline via
     * {@see ParallelContextMerger}, clears the cache entries, and branches on
     * the manifest's FailStrategy when the batch reports failures:
     *
     * - Under StopAndCompensate or StopImmediately with any failure, fires
     *   onFailure then onComplete and does NOT dispatch the next step.
     *   StopAndCompensate additionally dispatches the reversed
     *   {@see CompensationStepJob} chain.
     * - Under SkipAndContinue (or any strategy with no failures) the merged
     *   manifest is advanced past the group and the next PipelineStepJob is
     *   dispatched via {@see PipelineStepJob::dispatchWrapperFor()}.
     * - When the group is the last step in the pipeline, the merged manifest's
     *   success tail fires onSuccess then onComplete to match the queued-mode
     *   terminal-callback contract.
     *
     * Runs on whichever worker picks up the batch-finalization job; Laravel
     * guarantees this runs exactly once per batch.
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
    public static function finalize(
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

        // Read (without deleting) the succeeded signal and the optional context
        // entry. Cache::get + Cache::forget (instead of Cache::pull) leaves the
        // entry readable if the callback retries after a downstream dispatch
        // failure; the explicit forget at the end of the loop still cleans up
        // on the normal path.
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
                // Belt-and-suspenders: clear the nested cursor before the
                // compensation chain is serialized so failed_jobs records do
                // not carry stale cursor state.
                $manifest->nestedCursor = [];
                QueuedCompensationDispatcher::dispatchChain($manifest);
            }

            // Wrap the callback invocations so a throwing user callback is
            // logged with full fan-in context rather than raw-propagating out
            // of the batch-then worker. The fan-in path is terminal:
            // rethrowing here would land the then-callback job in failed_jobs
            // without any recovery path, so we log and continue to onComplete.
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

        // No terminal failure: advance past the group before dispatching the
        // next step or firing the success tail. Placing advancement AFTER the
        // hasFailures() branch preserves the "failed group stays at its
        // position" invariant that mirrors the sync path.
        PipelineStepJob::advanceCursorOrOuter($manifest);

        if (PipelineStepJob::hasMorePositions($manifest)) {
            PipelineStepJob::dispatchWrapperFor($manifest);

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
     * Resolve the per-sub-step config entry from a pre-resolved parallel shape.
     *
     * Reads `$parallelShape['configs'][$subIndex]` and falls back to the
     * default null-config shape when the shape is null, malformed, or missing
     * the requested sub-step.
     *
     * @param array<string, mixed>|null $parallelShape The resolved parallel shape (already navigated via stepConfigAt when inside a nested group).
     * @param int $subIndex The zero-based declaration-order index of the sub-step.
     * @return array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} The resolved per-sub-step configuration.
     */
    private static function resolveSubConfig(?array $parallelShape, int $subIndex): array
    {
        $default = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

        if ($parallelShape === null || ($parallelShape['type'] ?? null) !== 'parallel') {
            return $default;
        }

        /** @var array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}> $configs */
        $configs = $parallelShape['configs'] ?? [];

        return $configs[$subIndex] ?? $default;
    }
}
