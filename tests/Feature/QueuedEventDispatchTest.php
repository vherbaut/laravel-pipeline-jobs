<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
    Event::fake([
        PipelineStepCompleted::class,
        PipelineStepFailed::class,
        PipelineCompleted::class,
    ]);
});

it('fires PipelineStepCompleted for each flat queued step that completes successfully', function (): void {
    JobPipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->shouldBeQueued()
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatchedTimes(PipelineStepCompleted::class, 2);
});

it('fires PipelineCompleted exactly once at terminal queued success', function (): void {
    JobPipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->shouldBeQueued()
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
});

it('does not fire any pipeline event when dispatchEvents is off in queued mode', function (): void {
    JobPipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->shouldBeQueued()
        ->send(new SimpleContext)
        ->run();

    Event::assertNotDispatched(PipelineStepCompleted::class);
    Event::assertNotDispatched(PipelineStepFailed::class);
    Event::assertNotDispatched(PipelineCompleted::class);
});

it('fires PipelineStepFailed when a queued step throws under StopImmediately', function (): void {
    try {
        JobPipeline::make([TrackExecutionJobA::class, FailingJob::class])
            ->shouldBeQueued()
            ->dispatchEvents()
            ->send(new SimpleContext)
            ->run();
    } catch (Throwable) {
        // sync driver propagates the exception inline, which we allow here.
    }

    Event::assertDispatched(
        PipelineStepFailed::class,
        fn (PipelineStepFailed $event): bool => $event->stepClass === FailingJob::class
            && $event->stepIndex === 1,
    );
});

it('fires PipelineCompleted once at the queued terminal failure exit under StopImmediately', function (): void {
    try {
        JobPipeline::make([TrackExecutionJobA::class, FailingJob::class])
            ->shouldBeQueued()
            ->dispatchEvents()
            ->send(new SimpleContext)
            ->run();
    } catch (Throwable) {
    }

    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
});

it('fires PipelineCompleted once at the queued terminal failure exit under StopAndCompensate', function (): void {
    try {
        (new PipelineBuilder)
            ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
            ->step(FailingJob::class)
            ->onFailure(FailStrategy::StopAndCompensate)
            ->shouldBeQueued()
            ->dispatchEvents()
            ->send(new SimpleContext)
            ->run();
    } catch (Throwable) {
    }

    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
});

it('defaults dispatchEvents to false on a legacy queued payload missing the key', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
        dispatchEvents: true,
    );

    $payload = $manifest->__serialize();
    unset($payload['dispatchEvents']);

    /** @var PipelineManifest $legacy */
    $legacy = (new ReflectionClass(PipelineManifest::class))->newInstanceWithoutConstructor();
    $legacy->__unserialize($payload);

    expect($legacy->dispatchEvents)->toBeFalse();
});
