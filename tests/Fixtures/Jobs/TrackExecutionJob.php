<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

final class TrackExecutionJob
{
    /** @var array<int, string> */
    public static array $executionOrder = [];

    protected ?PipelineManifest $pipelineManifest = null;

    public function handle(): void
    {
        self::$executionOrder[] = self::class;
    }
}
