<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

final class FailingJob
{
    use InteractsWithPipeline;

    /**
     * Throw a RuntimeException to simulate a failing pipeline step.
     *
     * @return void
     *
     * @throws \RuntimeException Always, to exercise failure paths in tests.
     */
    public function handle(): void
    {
        throw new \RuntimeException('Job failed intentionally');
    }
}
