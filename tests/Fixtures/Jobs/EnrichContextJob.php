<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

final class EnrichContextJob
{
    use InteractsWithPipeline;

    /**
     * Set the injected SimpleContext's $name to "enriched" as a probe for downstream steps.
     *
     * @return void
     */
    public function handle(): void
    {
        $context = $this->pipelineContext();

        if ($context instanceof SimpleContext) {
            $context->name = 'enriched';
        }
    }
}
