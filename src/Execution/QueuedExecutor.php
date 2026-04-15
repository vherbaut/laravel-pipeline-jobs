<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Exceptions\ContextSerializationFailed;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;

/**
 * Asynchronous pipeline executor that dispatches the first step to the queue.
 *
 * Validates the context at dispatch time to convert silent queue failures
 * into explicit ContextSerializationFailed exceptions, then wraps the first
 * step in a PipelineStepJob carrying the full manifest. Each queued step
 * self-dispatches the next via the embedded-manifest pattern until the
 * pipeline is complete. Always returns null since execution is deferred.
 *
 * Per-step queue / connection / sync configuration is applied to the
 * first-step dispatch via `stepConfigs[0]`; subsequent steps' configurations
 * are applied by `PipelineStepJob::dispatchNextStep()` as each wrapper
 * self-dispatches the next. When `stepConfigs[0]['sync'] === true`,
 * `execute()` does NOT return until the inline-dispatched wrapper has fully
 * executed, which under `FailStrategy::StopImmediately` means a synchronous
 * exception may propagate out of `dispatch_sync` into the caller, so
 * `shouldBeQueued()->run()` is no longer always fire-and-forget when the
 * first step is marked sync.
 */
final class QueuedExecutor implements PipelineExecutor
{
    /**
     * Dispatch the first pipeline step to the queue and return null.
     *
     * Validates the PipelineContext (when present) so serialization errors
     * surface synchronously before any job is enqueued, then delegates to
     * dispatchFirstStep() which branches on `stepConfigs[0]['sync']` and
     * conditionally applies `onQueue` / `onConnection` overrides.
     *
     * @param PipelineDefinition $definition The immutable pipeline description containing steps and configuration.
     * @param PipelineManifest $manifest The mutable execution state carrying context and step progress.
     * @return PipelineContext|null Always null; async execution has no synchronous result.
     *
     * @throws ContextSerializationFailed When the context holds a non-serializable property.
     */
    public function execute(PipelineDefinition $definition, PipelineManifest $manifest): ?PipelineContext
    {
        $manifest->context?->validateSerializable();

        $this->dispatchFirstStep($manifest);

        return null;
    }

    /**
     * Dispatch the first PipelineStepJob wrapper, applying per-step config.
     *
     * Branching driven by `stepConfigs[0]`:
     * - `sync === true` → `dispatch_sync()`: runs the wrapper synchronously
     *   in the current PHP process. Exceptions propagate synchronously into
     *   the caller's stack frame. When a `timeout` is declared, it is still
     *   assigned on the wrapper (observationally inert because `dispatch_sync`
     *   does not install a `pcntl_alarm`) so test assertions observe the
     *   declared value on both branches symmetrically.
     * - `sync === false` with explicit queue / connection → `dispatch()` with
     *   the returned PendingDispatch mutated via fluent `onQueue()` / `onConnection()`.
     * - `sync === false` with null queue / connection → no-op mutations
     *   (the default Laravel queue / connection is preserved).
     *
     * When `timeout` is non-null, it is assigned to the wrapper's public
     * `$timeout` property (inherited from Laravel's Queueable trait).
     * Laravel's queue worker reads the property at job pickup and calls
     * `pcntl_alarm($timeout)` before invoking `handle()`; on SIGALRM the
     * worker is killed and the wrapper lands in `failed_jobs`. The property
     * is assigned only when non-null so `Bus::fake()` assertions observe
     * `$job->timeout === null` as the default-unset signal.
     *
     * @param PipelineManifest $manifest The manifest whose `stepConfigs[0]` drives the dispatch branching.
     *
     * @return void
     */
    private function dispatchFirstStep(PipelineManifest $manifest): void
    {
        $config = $manifest->stepConfigs[0] ?? ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

        if ((bool) $config['sync']) {
            $job = new PipelineStepJob($manifest);

            if (($config['timeout'] ?? null) !== null) {
                $job->timeout = $config['timeout'];
            }

            dispatch_sync($job);

            return;
        }

        $job = new PipelineStepJob($manifest);

        if ($config['queue'] !== null) {
            $job->onQueue($config['queue']);
        }

        if ($config['connection'] !== null) {
            $job->onConnection($config['connection']);
        }

        if (($config['timeout'] ?? null) !== null) {
            $job->timeout = $config['timeout'];
        }

        dispatch($job);
    }
}
