<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Events;

use Throwable;

// Note: shares its basename with Vherbaut\LaravelPipelineJobs\Exceptions\CompensationFailed.
// Callers that import both must alias the event, e.g.
// `use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;`.

/**
 * Broadcast when a saga compensation fails during pipeline rollback.
 *
 * Dispatched from three places:
 *
 * - SyncExecutor::runCompensationChain() after swallowing a per-compensation
 *   throwable (best-effort sync semantics are preserved; the chain continues).
 * - RecordingExecutor::runCompensation() to mirror production observability
 *   inside tests that rely on Pipeline::fake()->recording().
 * - CompensationStepJob::failed() after the queued wrapper exhausts its
 *   single attempt (tries = 1).
 *
 * The event fires unconditionally on compensation failure, independent of any
 * user opt-in to pipeline events. Compensation failure is operational alerting
 * (NFR6): users who haven't subscribed to the happy-path events still need to
 * know when a rollback fails.
 */
final class CompensationFailed
{
    /**
     * Create a new CompensationFailed event payload.
     *
     * @param string $pipelineId Unique identifier of the pipeline run whose compensation failed.
     * @param string $compensationClass Fully qualified class name of the compensation job that threw.
     * @param string|null $failedStepClass Fully qualified class name of the original failing step that triggered the compensation chain, or null when the manifest never recorded the failing step (defensive path for manually constructed manifests in tests).
     * @param Throwable|null $originalException Throwable raised by the failing step. Null in queued compensation because Throwables are excluded from serialized queue payloads (NFR19).
     * @param Throwable $compensationException Throwable raised by the compensation job itself; always non-null since the event only fires on compensation failure.
     * @return void
     */
    public function __construct(
        public readonly string $pipelineId,
        public readonly string $compensationClass,
        public readonly ?string $failedStepClass,
        public readonly ?Throwable $originalException,
        public readonly Throwable $compensationException,
    ) {}
}
