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
 */
final class QueuedExecutor implements PipelineExecutor
{
    /**
     * Dispatch the first pipeline step to the queue and return null.
     *
     * Validates the PipelineContext (when present) so serialization errors
     * surface synchronously before any job is enqueued, then dispatches a
     * PipelineStepJob carrying the full manifest. The wrapper job performs
     * each step and self-dispatches the next one.
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

        dispatch(new PipelineStepJob($manifest));

        return null;
    }
}
