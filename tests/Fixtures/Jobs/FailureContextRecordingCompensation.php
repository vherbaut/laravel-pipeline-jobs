<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;

/**
 * Fixture exercising the two-argument compensate() signature introduced in Story 5.3.
 *
 * Records the FailureContext passed by the executor so tests can assert that
 * the failure-context bridge propagates correctly through sync, recording,
 * and queued code paths. The fixture is static-log based to mirror the
 * existing CompensateJobA/B/C pattern and keep assertions trivially
 * serializable.
 */
final class FailureContextRecordingCompensation implements CompensableJob
{
    /**
     * Last FailureContext received by compensate(), or null when not yet called.
     *
     * @var FailureContext|null
     */
    public static ?FailureContext $lastFailure = null;

    /**
     * Record the FailureContext passed by the executor into the static log.
     *
     * @param PipelineContext $context The pipeline context present at the failure point.
     * @param FailureContext|null $failure Snapshot of the recorded failure metadata, or null when no failure has been recorded.
     * @return void
     */
    public function compensate(PipelineContext $context, ?FailureContext $failure = null): void
    {
        self::$lastFailure = $failure;
    }
}
