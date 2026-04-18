<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];
});

it('queued nested pipeline executes inner steps in order and preserves context to outer subsequent steps', function (Closure $builderFactory): void {
    $builderFactory()
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::nest(JobPipeline::make([TrackExecutionJobB::class, EnrichContextJob::class])),
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->nest(JobPipeline::make([TrackExecutionJobB::class, EnrichContextJob::class]))
        ->step(TrackExecutionJobC::class),
]);

it('queued three-level nested pipeline executes all innermost steps in declaration order', function (): void {
    $innermost = JobPipeline::make([TrackExecutionJobB::class]);
    $middle = JobPipeline::make([TrackExecutionJobA::class])->nest($innermost);

    (new PipelineBuilder)
        ->nest($middle)
        ->step(TrackExecutionJobC::class)
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

it('queued nested pipeline under StopImmediately rethrows on first inner failure', function (): void {
    $inner = JobPipeline::make([TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class]);

    $builder = (new PipelineBuilder)
        ->nest($inner)
        ->step(TrackExecutionJobC::class)
        ->shouldBeQueued();

    // Queued mode rethrows the original Throwable unwrapped (symmetric with the
    // pre-Story-8.2 top-level queued behaviour; SyncExecutor wraps as
    // StepExecutionFailed but the queued wrapper propagates the raw cause).
    expect(fn () => $builder->run())->toThrow(RuntimeException::class);

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class])
        ->and(TrackExecutionJob::$executionOrder)->not->toContain(TrackExecutionJobC::class);
});

it('queued nested pipeline under SkipAndContinue resumes after an inner failure', function (): void {
    $inner = JobPipeline::make([TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class]);

    (new PipelineBuilder)
        ->nest($inner)
        ->step(TrackExecutionJobC::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->shouldBeQueued()
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});
