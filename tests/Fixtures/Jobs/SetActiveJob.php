<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

final class SetActiveJob
{
    protected ?PipelineManifest $pipelineManifest = null;

    /**
     * Flip the injected SimpleContext's $active flag to true and record execution order.
     *
     * Drives runtime-evaluation tests by mutating context mid-pipeline so
     * that a later conditional step can observe the mutation.
     *
     * @return void
     */
    public function handle(): void
    {
        $context = $this->pipelineManifest?->context;

        if ($context instanceof SimpleContext) {
            $context->active = true;
        }

        TrackExecutionJob::$executionOrder[] = self::class;
    }
}
