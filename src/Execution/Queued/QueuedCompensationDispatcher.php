<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Queued;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Execution\CompensationStepJob;

/**
 * Queued compensation chain dispatcher.
 *
 * Reverses `$manifest->completedSteps`, looks up the compensation class for
 * each completed step in `$manifest->compensationMapping`, and accumulates a
 * {@see CompensationStepJob} wrapper per mapped entry. When the resulting
 * array is non-empty, dispatches `Bus::chain($jobs)` so each compensation
 * runs on its own worker in reverse order. Short-circuits to no dispatch
 * when no completed step declared a compensation.
 *
 * NFR19: Clears the non-serializable Throwable from the manifest BEFORE
 * `Bus::chain()` serializes each CompensationStepJob's payload so queued
 * compensations never fail deserialization. The wrapped jobs share the same
 * manifest reference, so nulling here protects every queued compensation
 * payload.
 *
 * @internal
 */
final class QueuedCompensationDispatcher
{
    /**
     * Dispatch the reversed compensation chain for a manifest.
     *
     * @param PipelineManifest $manifest The manifest whose completedSteps drive the reversed compensation chain.
     * @return void
     */
    public static function dispatchChain(PipelineManifest $manifest): void
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

        // NFR19: clear the non-serializable throwable before Bus::chain serializes the payloads.
        $manifest->failureException = null;

        try {
            Bus::chain($chain)->dispatch();
        } catch (Throwable $dispatchException) {
            // A failure to dispatch the compensation chain (queue driver
            // unavailable, serialization failure) must not mask the original
            // step exception. Log the dispatch failure and let the caller
            // rethrow the real step exception caught upstream.
            Log::error('Pipeline compensation chain dispatch failed', [
                'pipelineId' => $manifest->pipelineId,
                'failedStepClass' => $manifest->failedStepClass,
                'exception' => $dispatchException->getMessage(),
            ]);
        }
    }
}
