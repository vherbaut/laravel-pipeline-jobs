<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Services\SimpleContextRegistry;

/**
 * Invokable Action fixture combining container DI with named context binding (Story 9.4 DD #3).
 *
 * Pins that the dispatcher's `'context'` named-parameter binding composes
 * cleanly with Laravel's container resolution of unrelated dependencies.
 */
final class ActionWithDependencyJob
{
    /**
     * Record the resolved context's name into the injected registry.
     *
     * @param SimpleContextRegistry $registry Container-resolved registry service.
     * @param PipelineContext|null $context Pipeline context bound by name via the dispatcher.
     * @return void
     */
    public function __invoke(SimpleContextRegistry $registry, ?PipelineContext $context): void
    {
        $registry->record($context instanceof SimpleContext ? $context->name : null);
    }
}
