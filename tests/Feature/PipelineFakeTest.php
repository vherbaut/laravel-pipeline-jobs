<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Events\TestOrderPlacedEvent;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\HookRecorder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\IncrementCountJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
    HookRecorder::reset();
});

it('fakes a pipeline in a service-like context and asserts dispatch', function (): void {
    Pipeline::fake();

    // Simulate service code that triggers a pipeline
    Pipeline::make([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ])->send(new SimpleContext)->run();

    Pipeline::assertPipelineRan();
    Pipeline::assertPipelineRanWith([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
    Pipeline::assertPipelineRanTimes(1);
    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
});

it('fakes with array API entry point', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class, FakeJobB::class, FakeJobC::class])->run();

    Pipeline::assertPipelineRanWith([FakeJobA::class, FakeJobB::class, FakeJobC::class]);
});

it('fakes with fluent step() API entry point', function (): void {
    Pipeline::fake();

    Pipeline::make()
        ->step(FakeJobA::class)
        ->step(FakeJobB::class)
        ->step(FakeJobC::class)
        ->run();

    Pipeline::assertPipelineRanWith([FakeJobA::class, FakeJobB::class, FakeJobC::class]);
});

it('records context without executing when send() is used', function (): void {
    Pipeline::fake();

    $context = new SimpleContext;
    $context->name = 'test-value';

    Pipeline::make([EnrichContextJob::class, ReadContextJob::class])
        ->send($context)
        ->run();

    Pipeline::assertPipelineRan();
    expect(ReadContextJob::$readName)->toBeNull()
        ->and(TrackExecutionJob::$executionOrder)->toBeEmpty();
});

it('records listener registration without actually registering an event listener', function (): void {
    Pipeline::fake();

    Pipeline::listen(TestOrderPlacedEvent::class, [
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]);

    // Fire the event; since the listener was never truly registered, nothing should execute
    event(new TestOrderPlacedEvent('should-not-execute'));

    Pipeline::assertPipelineRan();
    Pipeline::assertPipelineRanWith([TrackExecutionJobA::class, TrackExecutionJobB::class]);
    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
});

it('tracks multiple pipelines with different step configurations', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();
    Pipeline::make([FakeJobB::class, FakeJobC::class])->run();
    Pipeline::make([FakeJobA::class, FakeJobB::class, FakeJobC::class])->run();

    Pipeline::assertPipelineRanTimes(3);
    Pipeline::assertPipelineRanWith([FakeJobA::class]);
    Pipeline::assertPipelineRanWith([FakeJobB::class, FakeJobC::class]);
    Pipeline::assertPipelineRanWith([FakeJobA::class, FakeJobB::class, FakeJobC::class]);
});

it('does not affect existing tests when fake is not active', function (): void {
    // Without Pipeline::fake(), the real pipeline should execute
    $result = Pipeline::make([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ])->send(new SimpleContext)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]);
});

it('intercepts shouldBeQueued() without dispatching to queue', function (): void {
    Pipeline::fake();

    Pipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Pipeline::assertPipelineRan();
    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
});

// --- return() ---

it('applies the return closure in recording mode and returns its result', function (): void {
    Pipeline::fake()->recording();

    $result = Pipeline::make([IncrementCountJob::class])
        ->send(new SimpleContext)
        ->return(fn ($ctx) => $ctx->count * 10)
        ->run();

    expect($result)->toBe(10);

    Pipeline::assertStepExecuted(IncrementCountJob::class);
    Pipeline::assertContextHas('count', 1);
});

it('returns null in fake mode even when ->return() is registered (no execution happened)', function (): void {
    Pipeline::fake();

    $result = Pipeline::make([IncrementCountJob::class])
        ->send(new SimpleContext)
        ->return(fn ($ctx) => $ctx->count * 99)
        ->run();

    expect($result)->toBeNull();

    Pipeline::assertPipelineRan();
    Pipeline::assertPipelineRanWith([IncrementCountJob::class]);
});

it('skips the return closure in recording mode when a step fails and returns null', function (): void {
    Pipeline::fake()->recording();

    $closureCallCount = 0;

    $result = Pipeline::make([IncrementCountJob::class, FailingJob::class])
        ->send(new SimpleContext)
        ->return(function ($ctx) use (&$closureCallCount): int {
            $closureCallCount++;

            return $ctx instanceof SimpleContext ? $ctx->count * 10 : -1;
        })
        ->run();

    // Parity with PipelineBuilder::run(): a step failure aborts before ->return() fires.
    expect($result)->toBeNull()
        ->and($closureCallCount)->toBe(0);

    Pipeline::assertStepExecuted(IncrementCountJob::class);
    Pipeline::assertStepNotExecuted(FailingJob::class);
});

// --- Story 6.1: Per-step lifecycle hooks on PipelineFake / FakePipelineBuilder ---

it('fires registered hooks in Pipeline::fake()->recording() mode', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->beforeEach(function (StepDefinition $step): void {
            HookRecorder::$beforeEach[] = $step->jobClass;
        })
        ->afterEach(function (StepDefinition $step): void {
            HookRecorder::$afterEach[] = $step->jobClass;
        })
        ->send(new SimpleContext)
        ->run();

    expect(HookRecorder::$beforeEach)->toBe([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->and(HookRecorder::$afterEach)->toBe([TrackExecutionJobA::class, TrackExecutionJobB::class]);
});

it('does not fire hooks in Pipeline::fake() default mode because no steps run', function (): void {
    Pipeline::fake();

    Pipeline::make([TrackExecutionJobA::class])
        ->beforeEach(function (StepDefinition $step): void {
            HookRecorder::$beforeEach[] = $step->jobClass;
        })
        ->send(new SimpleContext)
        ->run();

    expect(HookRecorder::$beforeEach)->toBe([])
        ->and(TrackExecutionJob::$executionOrder)->toBe([]);
});

it('exposes beforeEach/afterEach/onStepFailed on FakePipelineBuilder delegating to the underlying builder', function (): void {
    Pipeline::fake();

    $beforeHook = function (StepDefinition $step) {};
    $afterHook = function (StepDefinition $step) {};
    $failedHook = function (StepDefinition $step, $ctx, Throwable $e) {};

    Pipeline::make([TrackExecutionJobA::class])
        ->beforeEach($beforeHook)
        ->afterEach($afterHook)
        ->onStepFailed($failedHook)
        ->send(new SimpleContext)
        ->run();

    $recorded = Pipeline::recordedPipelines()[0] ?? null;
    expect($recorded)->not->toBeNull();
    expect($recorded->definition->beforeEachHooks)->toBe([$beforeHook])
        ->and($recorded->definition->afterEachHooks)->toBe([$afterHook])
        ->and($recorded->definition->onStepFailedHooks)->toBe([$failedHook]);
});
