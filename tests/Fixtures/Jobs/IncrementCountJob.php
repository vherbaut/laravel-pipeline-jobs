<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

final class IncrementCountJob
{
    use InteractsWithPipeline;

    /**
     * Increment the injected SimpleContext's $count as a probe for test assertions.
     *
     * @return void
     */
    public function handle(): void
    {
        $context = $this->pipelineContext();

        if ($context instanceof SimpleContext) {
            $context->count++;
        }
    }
}
