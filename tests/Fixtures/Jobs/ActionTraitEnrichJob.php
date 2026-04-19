<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

/**
 * Invokable Action fixture using InteractsWithPipeline trait (Story 9.4 AC #12).
 *
 * Pins that the trait works on Action-shape classes without declaring a
 * `?PipelineContext` parameter on `__invoke()`.
 */
final class ActionTraitEnrichJob
{
    use InteractsWithPipeline;

    /**
     * Mutate the trait-resolved context to "action-trait-enriched".
     *
     * @return void
     */
    public function __invoke(): void
    {
        $context = $this->pipelineContext();

        if ($context instanceof SimpleContext) {
            $context->name = 'action-trait-enriched';
        }
    }
}
