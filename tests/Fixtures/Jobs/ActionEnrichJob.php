<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

/**
 * Invokable Action fixture for Story 9.4 (FR45).
 *
 * Receives the live pipeline context via the dispatcher's named-parameter
 * binding ($context). Mutates the SimpleContext name to "action-enriched".
 */
final class ActionEnrichJob
{
    /** @var int Static execution counter; tests reset this in beforeEach. */
    public static int $invocations = 0;

    /**
     * Set the resolved context's $name to "action-enriched".
     *
     * @param PipelineContext|null $context The pipeline context bound by StepInvocationDispatcher::call().
     * @return void
     */
    public function __invoke(?PipelineContext $context): void
    {
        self::$invocations++;

        if ($context instanceof SimpleContext) {
            $context->name = 'action-enriched';
        }
    }
}
