<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

final class FailingJob
{
    protected ?PipelineManifest $pipelineManifest = null;

    public function handle(): void
    {
        throw new \RuntimeException('Job failed intentionally');
    }
}
