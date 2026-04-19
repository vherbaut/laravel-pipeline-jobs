<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
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

it('fires completion events only for the selected branch flat step (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'left';

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->branch(fn (SimpleContext $ctx) => $ctx->name, [
            'left' => TrackExecutionJobB::class,
            'right' => TrackExecutionJobC::class,
        ])
        ->dispatchEvents()
        ->send($context)
        ->run();

    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobB::class
            && $event->stepIndex === 1,
    );
    Event::assertNotDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobC::class,
    );
});

it('fires PipelineStepFailed with the ConditionalBranch<> label when the selector throws (sync)', function (): void {
    $context = new SimpleContext;

    expect(fn () => (new PipelineBuilder)
        ->branch(static fn (SimpleContext $ctx) => throw new RuntimeException('boom'), [
            'a' => TrackExecutionJobA::class,
            'b' => TrackExecutionJobB::class,
        ])
        ->dispatchEvents()
        ->send($context)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    Event::assertDispatched(
        PipelineStepFailed::class,
        fn (PipelineStepFailed $event): bool => $event->stepClass === 'ConditionalBranch'
            && $event->stepIndex === 0
            && $event->exception instanceof RuntimeException,
    );
});

it('fires PipelineStepFailed with the ConditionalBranch<> label for an unknown selector key (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'unknown';

    expect(fn () => (new PipelineBuilder)
        ->branch(fn (SimpleContext $ctx) => $ctx->name, [
            'a' => TrackExecutionJobA::class,
            'b' => TrackExecutionJobB::class,
        ])
        ->dispatchEvents()
        ->send($context)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    Event::assertDispatched(
        PipelineStepFailed::class,
        fn (PipelineStepFailed $event): bool => str_starts_with($event->stepClass, 'ConditionalBranch'),
    );
});
