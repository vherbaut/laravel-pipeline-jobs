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

it('fires PipelineStepCompleted for each parallel sub-step with the outer group index (sync)', function (Closure $builderFactory): void {
    $builderFactory()
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

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
    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobA::class
            && $event->stepIndex === 0,
    );
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([TrackExecutionJobB::class, TrackExecutionJobC::class]),
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->parallel([TrackExecutionJobB::class, TrackExecutionJobC::class]),
]);

it('fires PipelineCompleted once even when a parallel group is the last outer position (sync)', function (): void {
    JobPipeline::make([
        TrackExecutionJobA::class,
        JobPipeline::parallel([TrackExecutionJobB::class, TrackExecutionJobC::class]),
    ])
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
});
