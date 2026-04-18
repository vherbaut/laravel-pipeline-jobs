<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use RuntimeException;
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;

/**
 * Throws a StepExecutionFailed wrapping a RuntimeException to simulate the
 * "inner step is itself a pipeline that failed" scenario from
 * deferred-work.md:25. Used to pin the double-wrap collapse behavior of
 * SyncExecutor::executeNestedPipeline() and PipelineStepJob::handle().
 */
final class StepExecutionFailedThrowingJob
{
    use InteractsWithPipeline;

    /**
     * Throw a StepExecutionFailed whose $previous is a RuntimeException.
     *
     * Mirrors what happens when a job's handle() internally dispatches
     * another pipeline that throws StepExecutionFailed: the enclosing
     * pipeline catches the inner StepExecutionFailed and, without the
     * unwrap, would wrap it AGAIN into a double-nested envelope.
     *
     * @return void
     *
     * @throws StepExecutionFailed Always, carrying a RuntimeException as the previous exception.
     */
    public function handle(): void
    {
        throw StepExecutionFailed::forStep(
            'inner-pipeline-id',
            0,
            self::class,
            new RuntimeException('root-cause'),
        );
    }
}
