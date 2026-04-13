<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

final class TrackExecutionJob
{
    use InteractsWithPipeline;

    /** @var array<int, string> */
    public static array $executionOrder = [];

    /**
     * Append this class name to the shared execution-order log for test assertions.
     *
     * @return void
     */
    public function handle(): void
    {
        self::$executionOrder[] = self::class;
    }
}
