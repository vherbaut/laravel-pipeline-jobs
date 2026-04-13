<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

final class TrackExecutionJobA
{
    protected ?PipelineManifest $pipelineManifest = null;

    /**
     * Append this class name to TrackExecutionJob's shared execution-order log.
     *
     * @return void
     */
    public function handle(): void
    {
        TrackExecutionJob::$executionOrder[] = self::class;
    }
}
