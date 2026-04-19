<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    Event::fake([
        PipelineStepCompleted::class,
        PipelineStepFailed::class,
        PipelineCompleted::class,
    ]);
    CompensateJobA::$executed = [];
    TrackExecutionJob::$executionOrder = [];
});

it('fires PipelineStepCompleted for each flat step that runs successfully (sync)', function (): void {
    JobPipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->dispatchEvents()
        ->run();

    Event::assertDispatchedTimes(PipelineStepCompleted::class, 2);
    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobA::class && $event->stepIndex === 0,
    );
    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobB::class && $event->stepIndex === 1,
    );
});

it('fires PipelineCompleted exactly once on terminal success (sync)', function (): void {
    JobPipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->dispatchEvents()
        ->run();

    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
});

it('does not fire any pipeline event when dispatchEvents flag is off (zero-overhead, sync)', function (): void {
    JobPipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])->run();

    Event::assertNotDispatched(PipelineStepCompleted::class);
    Event::assertNotDispatched(PipelineStepFailed::class);
    Event::assertNotDispatched(PipelineCompleted::class);
});

it('fires PipelineStepFailed when a step throws under StopImmediately (sync)', function (): void {
    expect(fn () => JobPipeline::make([TrackExecutionJobA::class, FailingJob::class])
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    Event::assertDispatched(
        PipelineStepFailed::class,
        fn (PipelineStepFailed $event): bool => $event->stepClass === FailingJob::class
            && $event->stepIndex === 1
            && $event->exception instanceof RuntimeException,
    );
});

it('fires PipelineCompleted once on terminal failure under StopImmediately (sync)', function (): void {
    expect(fn () => JobPipeline::make([TrackExecutionJobA::class, FailingJob::class])
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
});

it('fires PipelineStepFailed and not PipelineStepCompleted for the skipped step under SkipAndContinue (sync)', function (): void {
    JobPipeline::make([TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class])
        ->onFailure(FailStrategy::SkipAndContinue)
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatched(
        PipelineStepFailed::class,
        fn (PipelineStepFailed $event): bool => $event->stepClass === FailingJob::class,
    );
    Event::assertNotDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === FailingJob::class,
    );
    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobA::class,
    );
    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobB::class,
    );
    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
});

it('fires PipelineCompleted once on terminal failure under StopAndCompensate after compensation chain (sync)', function (): void {
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(CompensateJobA::$executed)->toBe([CompensateJobA::class]);
    Event::assertDispatchedTimes(PipelineCompleted::class, 1);
    Event::assertDispatched(
        PipelineStepFailed::class,
        fn (PipelineStepFailed $event): bool => $event->stepClass === FailingJob::class,
    );
});

it('does not fire step events for when()-skipped steps (sync)', function (): void {
    $skip = Step::when(fn (): bool => false, TrackExecutionJobB::class);

    JobPipeline::make([
        TrackExecutionJobA::class,
        $skip,
    ])
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobA::class,
    );
    Event::assertNotDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->stepClass === TrackExecutionJobB::class,
    );
    Event::assertNotDispatched(
        PipelineStepFailed::class,
        fn (PipelineStepFailed $event): bool => $event->stepClass === TrackExecutionJobB::class,
    );
});

it('payload carries context reference and pipelineId on PipelineStepCompleted (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'pay-check';

    JobPipeline::make([TrackExecutionJobA::class])
        ->dispatchEvents()
        ->send($context)
        ->run();

    Event::assertDispatched(
        PipelineStepCompleted::class,
        function (PipelineStepCompleted $event) use ($context): bool {
            return $event->context === $context
                && $event->pipelineId !== ''
                && $event->stepClass === TrackExecutionJobA::class;
        },
    );
});

it('fires PipelineCompleted AFTER the onComplete pipeline-level callback on terminal success (sync)', function (): void {
    $order = [];

    JobPipeline::make([TrackExecutionJobA::class])
        ->onComplete(function () use (&$order): void {
            $order[] = 'onComplete';
        })
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatched(PipelineCompleted::class, function () use (&$order): bool {
        $order[] = 'PipelineCompleted';

        return true;
    });

    expect($order)->toBe(['onComplete', 'PipelineCompleted']);
});
