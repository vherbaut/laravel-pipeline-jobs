<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

final class IncrementCountJob
{
    protected ?PipelineManifest $pipelineManifest = null;

    public function handle(): void
    {
        $context = $this->pipelineManifest?->context;

        if ($context instanceof SimpleContext) {
            $context->count++;
        }
    }
}
