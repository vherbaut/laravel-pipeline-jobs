<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Events\TestOrderPlacedEvent;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Events\TestOrderShippedEvent;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\IncrementCountJob;
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

it('registers a pipeline as a listener via Pipeline::listen() with no context resolver', function (): void {
    Pipeline::listen(TestOrderPlacedEvent::class, [
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]);

    event(new TestOrderPlacedEvent('one-line-shortcut'));

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]);
});

it('resolves context from the event when Pipeline::listen() is given a send closure', function (): void {
    Pipeline::listen(
        TestOrderPlacedEvent::class,
        [ReadContextJob::class],
        fn (TestOrderPlacedEvent $event) => tap(new SimpleContext, fn (SimpleContext $ctx) => $ctx->name = $event->orderId),
    );

    event(new TestOrderPlacedEvent('order-xyz-42'));

    expect(ReadContextJob::$readName)->toBe('order-xyz-42');
});

it('replaces a single-purpose Listener class with a single-job Pipeline::listen() call', function (): void {
    Pipeline::listen(TestOrderPlacedEvent::class, [TrackExecutionJobA::class]);

    event(new TestOrderPlacedEvent('single-job'));

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);
});

it('registers independent pipelines for distinct event classes without cross-talk', function (): void {
    Pipeline::listen(TestOrderPlacedEvent::class, [TrackExecutionJobA::class]);
    Pipeline::listen(TestOrderShippedEvent::class, [TrackExecutionJobB::class]);

    event(new TestOrderPlacedEvent('placed-1'));
    $afterPlaced = TrackExecutionJob::$executionOrder;

    TrackExecutionJob::$executionOrder = [];

    event(new TestOrderShippedEvent('shipped-1'));
    $afterShipped = TrackExecutionJob::$executionOrder;

    expect($afterPlaced)->toBe([TrackExecutionJobA::class])
        ->and($afterShipped)->toBe([TrackExecutionJobB::class]);
});

it('throws InvalidPipelineDefinition when Pipeline::listen() is called with an empty jobs array', function (): void {
    expect(fn () => Pipeline::listen(TestOrderPlacedEvent::class, []))
        ->toThrow(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');
});

it('produces a void listener that ignores ->return()', function (): void {
    $returnClosureCallCount = 0;
    $resolvedContext = new SimpleContext;

    $listener = (new PipelineBuilder([IncrementCountJob::class]))
        ->send(fn ($event) => $resolvedContext)
        ->return(function ($ctx) use (&$returnClosureCallCount): string {
            $returnClosureCallCount++;

            return 'should-not-be-returned';
        })
        ->toListener();

    $listenerReturn = $listener(new stdClass);

    expect($listenerReturn)->toBeNull()
        ->and($returnClosureCallCount)->toBe(0)
        ->and($resolvedContext->count)->toBe(1);
});
