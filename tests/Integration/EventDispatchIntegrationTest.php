<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\IncrementCountJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;

// IntegrationTestCase bootstrap is wired in tests/Pest.php for the
// tests/Integration/ path, so this file does not re-register uses(...).

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
    ReadContextJob::$readCount = null;
    Cache::flush();
    Event::fake([
        PipelineStepCompleted::class,
        PipelineStepFailed::class,
        PipelineCompleted::class,
    ]);
});

it('fires PipelineCompleted exactly once under real multi-hop queued execution with a parallel group', function (): void {
    (new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([EnrichContextJob::class, IncrementCountJob::class]),
        ReadContextJob::class,
    ]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->dispatchEvents()
        ->run();

    $this->drainQueue();

    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
});

it('fires PipelineStepCompleted for each parallel sub-step under real queued execution', function (): void {
    (new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([EnrichContextJob::class, IncrementCountJob::class]),
        ReadContextJob::class,
    ]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->dispatchEvents()
        ->run();

    $this->drainQueue();

    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === EnrichContextJob::class
            && $event->stepIndex === 1,
    );
    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === IncrementCountJob::class
            && $event->stepIndex === 1,
    );
});
