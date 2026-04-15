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
     * Three-branch logic driven by `stepConfigs[0]`:
     * - `sync === true` → `dispatch_sync()`: runs the wrapper synchronously
     *   in the current PHP process. Exceptions propagate synchronously into
     *   the caller's stack frame.
     * - `sync === false` with explicit queue / connection → `dispatch()` with
     *   the returned PendingDispatch mutated via fluent `onQueue()` / `onConnection()`.
     * - `sync === false` with null queue / connection → no-op mutations
     *   (the default Laravel queue / connection is preserved; the conditional
     *   avoids degrading test-assertion signal by never calling
     *   `onQueue(null)` / `onConnection(null)`).
     *
     * Laravel's `dispatch()` helper returns a `PendingDispatch` object whose
     * `onQueue()` / `onConnection()` methods are fluent; the actual queue
     * push occurs when the object is destroyed at end of scope. The fluent
     * chain form below keeps dispatch and overrides in a single expression
     * so the PendingDispatch is fully configured before it goes out of scope.
     *
     * @param PipelineManifest $manifest The manifest whose `stepConfigs[0]` drives the dispatch branching.
     *
     * @return void
     */
    private function dispatchFirstStep(PipelineManifest $manifest): void
    {
        $config = $manifest->stepConfigs[0] ?? ['queue' => null, 'connection' => null, 'sync' => false];

        if ((bool) $config['sync']) {
            dispatch_sync(new PipelineStepJob($manifest));

            return;
        }

        $job = new PipelineStepJob($manifest);

        if ($config['queue'] !== null) {
            $job->onQueue($config['queue']);
        }

        if ($config['connection'] !== null) {
            $job->onConnection($config['connection']);
        }

        dispatch($job);
    }
}
