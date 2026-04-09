<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

final class TrackExecutionJobA
{
    protected ?PipelineManifest $pipelineManifest = null;

    public function handle(): void
    {
        TrackExecutionJob::$executionOrder[] = self::class;
    }
}
