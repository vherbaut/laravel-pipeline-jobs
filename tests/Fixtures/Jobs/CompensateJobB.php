<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

final class CompensateJobB
{
    /** @var array<int, string> */
    public static array $executed = [];

    protected ?PipelineManifest $pipelineManifest = null;

    public function handle(): void
    {
        self::$executed[] = self::class;
    }
}
