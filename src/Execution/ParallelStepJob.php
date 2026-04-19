<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepConditionEvaluator;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvoker;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Internal queued wrapper executing a single sub-step of a parallel group.
 *
 * Not part of the public API. Dispatched in bulk by
 * PipelineStepJob::dispatchParallelBatch() as part of a Bus::batch() so N
 * wrappers run concurrently across workers for one outer pipeline position.
 * Each wrapper carries its own deep-clone of the PipelineManifest (and
 * therefore its own PipelineContext) so sub-steps run in isolation with no
 * shared state during the batch window. After handle() returns successfully,
 * the wrapper persists its final PipelineContext to the Laravel cache under a
 * key derived from `{pipelineId, groupIndex, subStepIndex}` so the
 * batch-level `then` callback can recover per-sub-step contributions via
 * ParallelContextMerger without requiring any new dependency beyond the
 * illuminate/* cache abstraction (NFR17).
 *
 * The wrapper's native $tries stays at 1 so a worker crash between the sub-
 * step's success and the cache write cannot re-run an already-succeeded
 * sub-step; retry is SEMANTIC, delivered via the in-process
 * invokeStepWithRetry() loop identical to PipelineStepJob.
 *
 * @internal
 */
final class ParallelStepJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Cache-key TTL (seconds) for the per-sub-step final context.
     *
     * Chosen long enough to outlive realistic batch durations while
     * short enough that stale entries do not accumulate indefinitely.
     * One hour matches Laravel's default cache expiration idioms.
     *
     * @var int
     */
    public const FINAL_CONTEXT_TTL = 3600;

    /**
     * Maximum attempts for the wrapper job.
     *
     * Locked to 1 — retry is semantic and delivered via the in-process loop
     * in invokeStepWithRetry(). See PipelineStepJob for the rationale.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Per-sub-step timeout in seconds (assigned at dispatch time).
     *
     * Laravel's worker reads this before invoking handle() and calls
     * pcntl_alarm($timeout); SIGALRM lands the wrapper in failed_jobs.
     * Null means "use Laravel's default worker timeout".
     *
     * @var int|null
     */
    public ?int $timeout = null;

    /**
     * Create a new parallel sub-step wrapper.
     *
     * @param PipelineManifest $manifest Mutable manifest (deep-cloned per sub-step at dispatch time).
     * @param int $groupIndex Outer pipeline position of the enclosing parallel group.
     * @param int $subStepIndex Zero-based declaration-order index of this sub-step within the group.
     * @param string $stepClass Fully qualified job class name of the sub-step to execute.
     * @return void
     */
    public function __construct(
        public PipelineManifest $manifest,
        public readonly int $groupIndex,
        public readonly int $subStepIndex,
        public readonly string $stepClass,
    ) {}

    /**
     * Build the cache key for a sub-step's final context.
     *
     * Exposed as a public static helper so the batch-level `then` callback
     * can recover contexts without reaching into ParallelStepJob internals.
     *
     * @param string $pipelineId The unique identifier of the enclosing pipeline run.
     * @param int $groupIndex The outer pipeline position of the parallel group.
     * @param int $subStepIndex The zero-based declaration-order index of the sub-step.
     *
     * @return string The Laravel cache key used to persist/retrieve the sub-step's final context.
     */
    public static function contextCacheKey(string $pipelineId, int $groupIndex, int $subStepIndex): string
    {
        return "pipeline:{$pipelineId}:parallel:{$groupIndex}:sub:{$subStepIndex}";
    }

    /**
     * Build the cache key for a sub-step's "succeeded" signal.
     *
     * Separate from contextCacheKey() so context-less pipelines (where
     * $manifest->context is null) still record sub-step completion for
     * saga compensation coverage. The signal is a boolean `true`; absence
     * of the key at fan-in means the sub-step either failed, was skipped,
     * or never ran.
     *
     * @param string $pipelineId The unique identifier of the enclosing pipeline run.
     * @param int $groupIndex The outer pipeline position of the parallel group.
     * @param int $subStepIndex The zero-based declaration-order index of the sub-step.
     *
     * @return string The Laravel cache key used to persist/retrieve the sub-step's succeeded signal.
     */
    public static function succeededCacheKey(string $pipelineId, int $groupIndex, int $subStepIndex): string
    {
        return "pipeline:{$pipelineId}:parallel:{$groupIndex}:sub:{$subStepIndex}:ok";
    }

    /**
     * Execute the sub-step inline in the current worker's process.
     *
     * Mirrors PipelineStepJob::handle() but simplified: no chain advancement
     * (the batch's then/catch callbacks drive fan-in), no terminal
     * onSuccess/onComplete firing (those belong to the enclosing pipeline's
     * tail). Flow: (a) evaluate the sub-step condition from
     * $manifest->stepConditions[$groupIndex]['entries'][$subStepIndex]
     * (null entry = no condition = run), (b) resolve the job via
     * app()->make(), inject the manifest via ReflectionProperty when the
     * target job exposes a pipelineManifest property, (c) fire beforeEach,
     * (d) call invokeStepWithRetry() with the per-sub-step config from
     * $manifest->stepConfigs[$groupIndex]['configs'][$subStepIndex], (e) fire
     * afterEach, (f) persist the final context to cache for the `then`
     * callback's merge step.
     *
     * Skipped sub-steps (condition evaluated to false) return early WITHOUT
     * persisting a context entry — the merger treats missing entries as
     * "no contribution" identically to failed sub-steps, which is correct
     * because a skipped sub-step did not mutate its context clone.
     *
     * Failures rethrow so Laravel's batch machinery records the sub-step
     * as failed; the enclosing batch's catch callback applies the
     * pipeline's FailStrategy at fan-in time. onStepFailed hooks fire here
     * BEFORE the rethrow so per-step observability is preserved.
     *
     * @return void
     *
     * @throws Throwable The sub-step's exception (after onStepFailed hooks fire) so Laravel marks the batch job failed.
     */
    public function handle(): void
    {
        try {
            if ($this->shouldSkipSubStep()) {
                return;
            }

            $job = app()->make($this->stepClass);

            if (property_exists($job, 'pipelineManifest')) {
                $property = new ReflectionProperty($job, 'pipelineManifest');
                $property->setValue($job, $this->manifest);
            }

            StepInvoker::fireHooks(
                $this->manifest->beforeEachHooks,
                StepDefinition::fromJobClass($this->stepClass),
                $this->manifest->context,
            );

            StepInvoker::invokeWithRetry($job, $this->resolveSubStepConfig(), $this->manifest->context);

            StepInvoker::fireHooks(
                $this->manifest->afterEachHooks,
                StepDefinition::fromJobClass($this->stepClass),
                $this->manifest->context,
            );

            $this->manifest->markStepCompleted($this->stepClass);

            // Persist the succeeded signal separately from the context so
            // context-less pipelines ($manifest->context === null) still
            // record completion at fan-in and retain saga compensation
            // coverage. Any Cache::put failure (e.g. non-serializable
            // context property) is logged and rethrown so the sub-step
            // surfaces as failed rather than silently dropping its
            // contribution at fan-in.
            try {
                Cache::put(
                    self::succeededCacheKey($this->manifest->pipelineId, $this->groupIndex, $this->subStepIndex),
                    true,
                    self::FINAL_CONTEXT_TTL,
                );

                if ($this->manifest->context !== null) {
                    Cache::put(
                        self::contextCacheKey($this->manifest->pipelineId, $this->groupIndex, $this->subStepIndex),
                        $this->manifest->context,
                        self::FINAL_CONTEXT_TTL,
                    );
                }
            } catch (Throwable $cacheException) {
                Log::error('Pipeline parallel sub-step cache persistence failed', [
                    'pipelineId' => $this->manifest->pipelineId,
                    'groupIndex' => $this->groupIndex,
                    'subStepIndex' => $this->subStepIndex,
                    'stepClass' => $this->stepClass,
                    'exception' => $cacheException->getMessage(),
                ]);

                throw $cacheException;
            }
        } catch (Throwable $exception) {
            $this->manifest->failureException = $exception;
            $this->manifest->failedStepClass = $this->stepClass;
            $this->manifest->failedStepIndex = $this->groupIndex;

            StepInvoker::fireHooks(
                $this->manifest->onStepFailedHooks,
                StepDefinition::fromJobClass($this->stepClass),
                $this->manifest->context,
                $exception,
            );

            Log::error('Pipeline parallel sub-step failed', [
                'pipelineId' => $this->manifest->pipelineId,
                'groupIndex' => $this->groupIndex,
                'subStepIndex' => $this->subStepIndex,
                'stepClass' => $this->stepClass,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Decide whether this sub-step should be skipped based on its condition entry.
     *
     * Reads the nested entry from
     * $manifest->stepConditions[$groupIndex]['entries'][$subStepIndex] per
     * the widened parallel-group shape. Returns false when no condition is
     * registered (null entry slot) or when the enclosing group entry is
     * missing/non-parallel (defensive: treats malformed shapes as
     * unconditional).
     *
     * @return bool True when the sub-step must be skipped, false when it should run.
     */
    private function shouldSkipSubStep(): bool
    {
        $groupEntry = $this->manifest->stepConditions[$this->groupIndex] ?? null;

        if (! is_array($groupEntry) || ($groupEntry['type'] ?? null) !== 'parallel') {
            return false;
        }

        /** @var array<int, array{closure: SerializableClosure, negated: bool}|null> $entries */
        $entries = $groupEntry['entries'];
        $entry = $entries[$this->subStepIndex] ?? null;

        // Wrap closure resolution + invocation so signature/deserialization
        // errors (InvalidSignatureException if app.key rotated, etc.) are
        // logged with full context instead of surfacing as an opaque sub-step
        // failure several stack frames up.
        try {
            return StepConditionEvaluator::shouldSkipEntry($entry, $this->manifest->context);
        } catch (Throwable $conditionException) {
            Log::error('Pipeline parallel sub-step condition evaluation failed', [
                'pipelineId' => $this->manifest->pipelineId,
                'groupIndex' => $this->groupIndex,
                'subStepIndex' => $this->subStepIndex,
                'stepClass' => $this->stepClass,
                'exception' => $conditionException->getMessage(),
            ]);

            throw $conditionException;
        }
    }

    /**
     * Resolve the per-sub-step config entry from the manifest's nested shape.
     *
     * Falls back to the default null-config shape when the enclosing group
     * entry is missing, malformed, or the sub-step index falls outside the
     * configured range. Defensive: the batch dispatcher always populates
     * the shape, but a handwritten manifest bypassing PipelineBuilder
     * would not.
     *
     * @return array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} The resolved per-sub-step configuration.
     */
    private function resolveSubStepConfig(): array
    {
        $default = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];
        $groupEntry = $this->manifest->stepConfigs[$this->groupIndex] ?? null;

        if (! is_array($groupEntry) || ($groupEntry['type'] ?? null) !== 'parallel') {
            return $default;
        }

        /** @var array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}> $configs */
        $configs = $groupEntry['configs'];

        return $configs[$this->subStepIndex] ?? $default;
    }
}
