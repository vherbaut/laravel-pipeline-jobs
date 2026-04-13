<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

final class TrackExecutionJobA
{
    use InteractsWithPipeline;

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
