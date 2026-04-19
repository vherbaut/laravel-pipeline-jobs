<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

/**
 * Invalid step fixture for Story 9.4 AC #4.
 *
 * Defines NEITHER `handle()` NOR `__invoke()`; used to verify that the
 * dispatcher throws InvalidPipelineDefinition with the documented message
 * at call time.
 */
final class InvalidStepClass
{
    /**
     * Unrelated method that the dispatcher must NOT pick up.
     *
     * @return void
     */
    public function report(): void
    {
        // intentionally empty
    }
}
