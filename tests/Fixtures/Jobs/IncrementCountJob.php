<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

final class IncrementCountJob
{
    protected ?PipelineManifest $pipelineManifest = null;

    /**
     * Increment the injected SimpleContext's $count as a probe for test assertions.
     *
     * @return void
     */
    public function handle(): void
    {
        $context = $this->pipelineManifest?->context;

        if ($context instanceof SimpleContext) {
            $context->count++;
        }
    }
}
