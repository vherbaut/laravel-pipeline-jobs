<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    Event::fake([
        PipelineStepCompleted::class,
        PipelineStepFailed::class,
        PipelineCompleted::class,
    ]);
});

it('fires PipelineStepCompleted for nested inner steps with the outer group index (sync)', function (Closure $builderFactory): void {
    $builderFactory()
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobA::class
            && $event->stepIndex === 0,
    );
    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobB::class
            && $event->stepIndex === 1,
    );
    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobC::class
            && $event->stepIndex === 1,
    );
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::nest(JobPipeline::make([TrackExecutionJobB::class, TrackExecutionJobC::class])),
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->nest(JobPipeline::make([TrackExecutionJobB::class, TrackExecutionJobC::class])),
]);

it('fires PipelineStepCompleted for nested-inside-nested leaves with the TOP outer index (sync)', function (): void {
    $innerInner = JobPipeline::make([TrackExecutionJobC::class]);
    $inner = JobPipeline::make([TrackExecutionJobB::class])->nest($innerInner);

    JobPipeline::make([
        TrackExecutionJobA::class,
        JobPipeline::nest($inner),
    ])
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobC::class
            && $event->stepIndex === 1,
    );
});

it('fires PipelineCompleted exactly once for a pipeline containing a nested group (sync)', function (): void {
    JobPipeline::make([
        TrackExecutionJobA::class,
        JobPipeline::nest(JobPipeline::make([TrackExecutionJobB::class])),
    ])
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
});
