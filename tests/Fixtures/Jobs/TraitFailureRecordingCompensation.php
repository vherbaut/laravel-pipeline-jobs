<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Context\FailureContext;

/**
 * Fixture exercising the Story 5.3 trait-based failureContext() accessor.
 *
 * Captures whatever $this->failureContext() returned during handle() into a
 * static log so tests can assert the trait surfaces the same failure metadata
 * that CompensableJob implementations receive via the second compensate()
 * argument.
 */
final class TraitFailureRecordingCompensation
{
    use InteractsWithPipeline;

    /**
     * Last FailureContext observed through the trait accessor, or null when not yet run.
     *
     * @var FailureContext|null
     */
    public static ?FailureContext $lastFailure = null;

    /**
     * Capture the failure-context snapshot available at compensation time.
     *
     * @return void
     */
    public function handle(): void
    {
        self::$lastFailure = $this->failureContext();
    }
}
