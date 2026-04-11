<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Events\TestOrderPlacedEvent;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Listeners\ReferenceListener;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
});

it('resolves context from the event via ->send() closure and executes a sync pipeline', function (): void {
    $listener = JobPipeline::make([ReadContextJob::class])
        ->send(fn (TestOrderPlacedEvent $event) => tap(new SimpleContext, fn (SimpleContext $ctx) => $ctx->name = $event->orderId))
        ->toListener();

    Event::listen(TestOrderPlacedEvent::class, $listener);

    event(new TestOrderPlacedEvent('order-abc-123'));

    expect(ReadContextJob::$readName)->toBe('order-abc-123');
});

it('executes a queued pipeline via listener bridge when shouldBeQueued() is used', function (): void {
    $listener = JobPipeline::make([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ])
        ->send(fn (TestOrderPlacedEvent $event) => new SimpleContext)
        ->shouldBeQueued()
        ->toListener();

    Event::listen(TestOrderPlacedEvent::class, $listener);

    event(new TestOrderPlacedEvent('queue-test'));

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

it('runs pipeline with null context when toListener() is called without send()', function (): void {
    $listener = JobPipeline::make([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ])->toListener();

    Event::listen(TestOrderPlacedEvent::class, $listener);

    event(new TestOrderPlacedEvent('no-send'));

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]);
});

it('matches hand-written Listener class behavior for NFR3 structural parity', function (): void {
    Event::listen(TestOrderPlacedEvent::class, [ReferenceListener::class, 'handle']);

    event(new TestOrderPlacedEvent('parity-1'));

    $referenceOrder = TrackExecutionJob::$executionOrder;
    $referenceContextName = ReadContextJob::$readName;

    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
    Event::forget(TestOrderPlacedEvent::class);

    $listener = JobPipeline::make([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        ReadContextJob::class,
    ])
        ->send(fn (TestOrderPlacedEvent $event) => tap(new SimpleContext, fn (SimpleContext $ctx) => $ctx->name = $event->orderId))
        ->toListener();

    Event::listen(TestOrderPlacedEvent::class, $listener);

    event(new TestOrderPlacedEvent('parity-1'));

    expect(TrackExecutionJob::$executionOrder)->toBe($referenceOrder)
        ->and(TrackExecutionJob::$executionOrder)->toBe([
            TrackExecutionJobA::class,
            TrackExecutionJobB::class,
        ])
        ->and(ReadContextJob::$readName)->toBe($referenceContextName)
        ->and(ReadContextJob::$readName)->toBe('parity-1');
});
