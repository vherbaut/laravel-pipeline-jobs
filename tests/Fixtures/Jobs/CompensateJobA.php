<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

final class CompensateJobA
{
    /** @var array<int, string> */
    public static array $executed = [];

    protected ?PipelineManifest $pipelineManifest = null;

    /**
     * Append this compensation class to the shared $executed log for test assertions.
     *
     * @return void
     */
    public function handle(): void
    {
        self::$executed[] = self::class;
    }
}
