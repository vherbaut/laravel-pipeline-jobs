<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;

/**
 * Fixture exercising the Story 5.2 CompensableJob interface bridge.
 *
 * Deliberately does NOT use the InteractsWithPipeline trait: when the
 * compensation bridge in SyncExecutor / RecordingExecutor / CompensationStepJob
 * detects the CompensableJob contract, it must call compensate($context)
 * directly and NOT attempt to reflect a pipelineManifest property or call
 * handle(). Tests reset $received between cases via beforeEach so each
 * scenario starts from a clean log.
 */
final class ContractBasedCompensation implements CompensableJob
{
    /**
     * Ordered log of PipelineContext class names received by compensate().
     *
     * @var array<int, string>
     */
    public static array $received = [];

    /**
     * Record the concrete PipelineContext class name received by the executor.
     *
     * Capturing the class name (rather than the instance) keeps the log
     * trivially serializable and decouples test assertions from context
     * identity, mirroring the existing static-log pattern used by
     * CompensateJobA/B/C.
     *
     * @param PipelineContext $context The pipeline context present at the failure point.
     * @return void
     */
    public function compensate(PipelineContext $context): void
    {
        self::$received[] = $context::class;
    }
}
