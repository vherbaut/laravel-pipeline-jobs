<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\ParallelStepGroup;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Events\TestOrderPlacedEvent;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingThenSucceedingJob;
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

// --- Story 6.2: Pipeline-level callbacks on PipelineFake / FakePipelineBuilder ---

it('pipeline-level: fires onSuccess and onComplete in Pipeline::fake()->recording() mode', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([TrackExecutionJobA::class])
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->run();

    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);
});

it('pipeline-level: does NOT fire callbacks in Pipeline::fake() default mode because no steps run', function (): void {
    Pipeline::fake();

    Pipeline::make([TrackExecutionJobA::class])
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->run();

    expect(HookRecorder::$fired)->toBe([])
        ->and(TrackExecutionJob::$executionOrder)->toBe([]);
});

it('pipeline-level: exposes onSuccess/onFailure/onComplete on FakePipelineBuilder delegating to the underlying builder', function (): void {
    Pipeline::fake();

    $onSuccess = function (?PipelineContext $ctx): void {};
    $onFailure = function (?PipelineContext $ctx, Throwable $e): void {};
    $onComplete = function (?PipelineContext $ctx): void {};

    Pipeline::make([TrackExecutionJobA::class])
        ->onSuccess($onSuccess)
        ->onFailure($onFailure)
        ->onComplete($onComplete)
        ->send(new SimpleContext)
        ->run();

    $recorded = Pipeline::recordedPipelines()[0] ?? null;
    expect($recorded)->not->toBeNull();
    expect($recorded->definition->onSuccess)->toBe($onSuccess)
        ->and($recorded->definition->onFailure)->toBe($onFailure)
        ->and($recorded->definition->onComplete)->toBe($onComplete);
});

it('pipeline-level: onFailure(FailStrategy) still routes through the fake', function (): void {
    Pipeline::fake();

    Pipeline::make([TrackExecutionJobA::class])
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run();

    $recorded = Pipeline::recordedPipelines()[0] ?? null;
    expect($recorded)->not->toBeNull();
    expect($recorded->definition->failStrategy)->toBe(FailStrategy::StopAndCompensate)
        ->and($recorded->definition->onFailure)->toBeNull();
});

it('pipeline-level: recording mode under SkipAndContinue fires onSuccess and onComplete, not onFailure', function (): void {
    // P3 regression: RecordingExecutor previously had no SkipAndContinue
    // branch — under a failing step with SkipAndContinue it fired onFailure
    // and threw StepExecutionFailed, violating AC #10 / AC #13. The fix
    // mirrors SyncExecutor by clearing failureException, advancing past
    // the failed step, and continuing to the success tail.
    Pipeline::fake()->recording();

    Pipeline::make([TrackExecutionJobA::class, FailingJob::class])
        ->onFailure(FailStrategy::SkipAndContinue)
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->onFailure(function (?PipelineContext $ctx, Throwable $e): void {
            HookRecorder::$fired[] = 'onFailure';
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->run();

    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);
});

it('per-step queue: Pipeline::fake() captures onQueue configuration on the recorded definition', function (): void {
    $fake = Pipeline::fake();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->onQueue('heavy')
        ->shouldBeQueued()
        ->run();

    $recorded = $fake->recordedPipelines();

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->definition->steps[0]->queue)->toBe('heavy');
});

it('per-step queue: Pipeline::fake() does NOT execute steps even when sync() and onQueue are configured', function (): void {
    Pipeline::fake();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->onQueue('heavy')->sync()
        ->shouldBeQueued()
        ->run();

    // Default fake mode records the definition without dispatching anything
    // and without running steps inline. The tracker stays empty regardless of
    // sync() because no execution path is taken.
    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
});

it('per-step queue: FakePipelineBuilder delegates onQueue, onConnection, sync, defaultQueue, and defaultConnection to the underlying PipelineBuilder', function (): void {
    $fake = Pipeline::fake();

    Pipeline::make()
        ->defaultQueue('background')
        ->defaultConnection('redis')
        ->step(TrackExecutionJobA::class)
        ->onQueue('heavy')
        ->onConnection('beanstalkd')
        ->sync()
        ->step(TrackExecutionJobB::class)
        ->shouldBeQueued()
        ->run();

    $recorded = $fake->recordedPipelines();
    $definition = $recorded[0]->definition;

    // sync() clears queue and connection because dispatch_sync overrides both
    expect($definition->steps[0]->queue)->toBeNull()
        ->and($definition->steps[0]->connection)->toBeNull()
        ->and($definition->steps[0]->sync)->toBeTrue()
        ->and($definition->steps[1]->queue)->toBeNull()
        ->and($definition->steps[1]->connection)->toBeNull()
        ->and($definition->steps[1]->sync)->toBeFalse()
        ->and($definition->defaultQueue)->toBe('background')
        ->and($definition->defaultConnection)->toBe('redis');
});

it('per-step retry: Pipeline::fake() captures retry/backoff/timeout on the recorded definition', function (): void {
    $fake = Pipeline::fake();

    Pipeline::make()
        ->step(FakeJobA::class)->retry(3)->backoff(5)->timeout(60)
        ->shouldBeQueued()
        ->run();

    $recorded = $fake->recordedPipelines();
    $definition = $recorded[0]->definition;

    expect($definition->steps[0]->retry)->toBe(3)
        ->and($definition->steps[0]->backoff)->toBe(5)
        ->and($definition->steps[0]->timeout)->toBe(60);
});

it('per-step retry: Pipeline::fake() does NOT invoke handle() even when retry is configured', function (): void {
    FailingThenSucceedingJob::reset();
    Pipeline::fake();

    Pipeline::make()
        ->step(FailingThenSucceedingJob::class)->retry(5)
        ->run();

    expect(FailingThenSucceedingJob::$invocationCount)->toBe(0);
});

it('per-step retry: FakePipelineBuilder delegates retry, backoff, timeout, defaultRetry, defaultBackoff, defaultTimeout to the underlying PipelineBuilder', function (): void {
    $fake = Pipeline::fake();

    Pipeline::make()
        ->defaultRetry(1)
        ->defaultBackoff(2)
        ->defaultTimeout(30)
        ->step(FakeJobA::class)
        ->retry(3)
        ->backoff(5)
        ->timeout(60)
        ->step(FakeJobB::class)
        ->shouldBeQueued()
        ->run();

    $recorded = $fake->recordedPipelines();
    $definition = $recorded[0]->definition;

    expect($definition->steps[0]->retry)->toBe(3)
        ->and($definition->steps[0]->backoff)->toBe(5)
        ->and($definition->steps[0]->timeout)->toBe(60)
        ->and($definition->steps[1]->retry)->toBeNull()
        ->and($definition->steps[1]->backoff)->toBeNull()
        ->and($definition->steps[1]->timeout)->toBeNull()
        ->and($definition->defaultRetry)->toBe(1)
        ->and($definition->defaultBackoff)->toBe(2)
        ->and($definition->defaultTimeout)->toBe(30);
});

it('per-step retry: Pipeline::fake()->recording() mode runs each step exactly once even when retry is configured', function (): void {
    FailingThenSucceedingJob::reset();
    FailingThenSucceedingJob::$attemptsBeforeSuccess = 99; // always fails

    Pipeline::fake()->recording();

    try {
        Pipeline::make()
            ->step(FailingThenSucceedingJob::class)->retry(3)
            ->run();
    } catch (Throwable) {
        // expected; retry is inert so only the first attempt throws
    }

    expect(FailingThenSucceedingJob::$invocationCount)->toBe(1);
});

it('Pipeline::fake()->dispatch() records the pipeline on destruct', function (): void {
    Pipeline::fake();

    Pipeline::dispatch([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->send(new SimpleContext);

    Pipeline::assertPipelineRan();
    Pipeline::assertPipelineRanWith([TrackExecutionJobA::class, TrackExecutionJobB::class]);
    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
});

it('Pipeline::fake()->recording() with dispatch() executes through RecordingExecutor', function (): void {
    Pipeline::fake()->recording();

    $ctx = new SimpleContext;
    $ctx->name = 'recorded';

    Pipeline::dispatch([EnrichContextJob::class, ReadContextJob::class])->send($ctx);

    $capturedAfterEnrich = Pipeline::getContextAfterStep(EnrichContextJob::class);
    expect($capturedAfterEnrich)->toBeInstanceOf(SimpleContext::class)
        ->and($capturedAfterEnrich->name)->toBe('enriched');
});

it('Pipeline::fake()->dispatch() and Pipeline::fake()->make()->run() produce equivalent recorded definitions', function (): void {
    $fake = Pipeline::fake();

    Pipeline::make([FakeJobA::class, FakeJobB::class])->send(new SimpleContext)->run();
    Pipeline::dispatch([FakeJobA::class, FakeJobB::class])->send(new SimpleContext);

    $recorded = $fake->recordedPipelines();
    expect($recorded)->toHaveCount(2);

    $makeSteps = array_map(fn ($s) => $s->jobClass, $recorded[0]->definition->steps);
    $dispatchSteps = array_map(fn ($s) => $s->jobClass, $recorded[1]->definition->steps);
    expect($makeSteps)->toBe($dispatchSteps)
        ->and($makeSteps)->toBe([FakeJobA::class, FakeJobB::class]);
});

it('Pipeline::fake() assertion helpers work against dispatch()-originated pipelines', function (): void {
    Pipeline::fake()->recording();
    Pipeline::assertNoPipelinesRan();

    $ctx = new SimpleContext;
    $ctx->name = 'probe';

    Pipeline::dispatch([TrackExecutionJobA::class])->send($ctx);

    Pipeline::assertPipelineRan();
    Pipeline::assertPipelineRanTimes(1);
    Pipeline::assertStepExecuted(TrackExecutionJobA::class);
    Pipeline::assertContextHas('name', 'probe');
});

it('records a pipeline that contains a parallel group', function (): void {
    $fake = Pipeline::fake();

    Pipeline::make([
        FakeJobA::class,
        JobPipeline::parallel([FakeJobB::class, FakeJobC::class]),
    ])->send(new SimpleContext)->run();

    $recorded = $fake->recordedPipelines();

    expect($recorded)->toHaveCount(1);

    $steps = $recorded[0]->definition->steps;
    expect($steps)->toHaveCount(2)
        ->and($steps[0])->toBeInstanceOf(StepDefinition::class)
        ->and($steps[1])->toBeInstanceOf(ParallelStepGroup::class)
        ->and($steps[1]->steps[0]->jobClass)->toBe(FakeJobB::class)
        ->and($steps[1]->steps[1]->jobClass)->toBe(FakeJobC::class);
});

it('assertParallelGroupExecuted passes when a matching group was recorded', function (): void {
    Pipeline::fake();

    Pipeline::make([
        FakeJobA::class,
        JobPipeline::parallel([FakeJobB::class, FakeJobC::class]),
    ])->send(new SimpleContext)->run();

    Pipeline::assertParallelGroupExecuted([FakeJobB::class, FakeJobC::class]);
});

it('assertParallelGroupExecuted fails when no matching group exists', function (): void {
    Pipeline::fake();

    Pipeline::make([
        FakeJobA::class,
        JobPipeline::parallel([FakeJobB::class]),
    ])->send(new SimpleContext)->run();

    expect(fn () => Pipeline::assertParallelGroupExecuted([FakeJobB::class, FakeJobC::class]))
        ->toThrow(AssertionFailedError::class, 'none of the 1 recorded group(s) matched');
});

it('assertParallelGroupExecuted fails with a clear message when no parallel group was recorded at all', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class, FakeJobB::class])->send(new SimpleContext)->run();

    expect(fn () => Pipeline::assertParallelGroupExecuted([FakeJobB::class]))
        ->toThrow(AssertionFailedError::class, 'recorded no parallel groups');
});

it('Pipeline::fake()->recording() replays a parallel group sequentially through RecordingExecutor', function (): void {
    Pipeline::fake()->recording();
    TrackExecutionJob::$executionOrder = [];

    Pipeline::make([
        TrackExecutionJobA::class,
        JobPipeline::parallel([TrackExecutionJobB::class, TrackExecutionJobC::class]),
    ])->send(new SimpleContext)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

// --- Story 8.2: NestedPipeline assertions -------------------------------------------------

it('records a nested pipeline in the recorded definition steps array', function (): void {
    Pipeline::fake();

    Pipeline::make([
        FakeJobA::class,
        JobPipeline::nest(JobPipeline::make([FakeJobB::class, FakeJobC::class]), 'child-flow'),
    ])->send(new SimpleContext)->run();

    Pipeline::assertNestedPipelineExecuted([FakeJobB::class, FakeJobC::class]);
    Pipeline::assertNestedPipelineExecuted([FakeJobB::class, FakeJobC::class], 'child-flow');
});

it('assertNestedPipelineExecuted fails when the expected class list does not match', function (): void {
    Pipeline::fake();

    Pipeline::make([
        FakeJobA::class,
        JobPipeline::nest(JobPipeline::make([FakeJobB::class])),
    ])->send(new SimpleContext)->run();

    expect(fn () => Pipeline::assertNestedPipelineExecuted([FakeJobB::class, FakeJobC::class]))
        ->toThrow(AssertionFailedError::class, 'none of the 1 recorded nested pipeline(s) matched');
});

it('assertNestedPipelineExecuted fails with a clear message when no nested pipeline was recorded at all', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class, FakeJobB::class])->send(new SimpleContext)->run();

    expect(fn () => Pipeline::assertNestedPipelineExecuted([FakeJobA::class]))
        ->toThrow(AssertionFailedError::class, 'recorded no nested pipelines');
});

it('Pipeline::fake()->recording() replays a nested pipeline sequentially through RecordingExecutor', function (): void {
    Pipeline::fake()->recording();
    TrackExecutionJob::$executionOrder = [];

    Pipeline::make([
        TrackExecutionJobA::class,
        JobPipeline::nest(JobPipeline::make([TrackExecutionJobB::class, TrackExecutionJobC::class])),
    ])->send(new SimpleContext)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

it('records a conditional-branch pipeline and assertConditionalBranchExecuted matches its keys', function (): void {
    Pipeline::fake();

    Pipeline::make([
        FakeJobA::class,
        Step::branch(fn ($ctx) => 'a', ['a' => FakeJobB::class, 'b' => FakeJobC::class], 'routing'),
    ])->send(new SimpleContext)->run();

    Pipeline::assertConditionalBranchExecuted(['a', 'b']);
    Pipeline::assertConditionalBranchExecuted(['a', 'b'], 'routing');
});

it('assertConditionalBranchExecuted fails with a clear message when no branch is recorded', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class, FakeJobB::class])->send(new SimpleContext)->run();

    expect(fn () => Pipeline::assertConditionalBranchExecuted(['a']))
        ->toThrow(AssertionFailedError::class, 'recorded no conditional branches');
});

it('assertConditionalBranchExecuted fails when recorded branch keys differ from the expected keys', function (): void {
    Pipeline::fake();

    Pipeline::make([
        Step::branch(fn ($ctx) => 'left', ['left' => FakeJobA::class, 'right' => FakeJobB::class]),
    ])->send(new SimpleContext)->run();

    expect(fn () => Pipeline::assertConditionalBranchExecuted(['foo', 'bar']))
        ->toThrow(AssertionFailedError::class, 'none of the 1 recorded branch group(s) matched');
});

it('Pipeline::fake()->recording() replays the selected branch through RecordingExecutor', function (): void {
    Pipeline::fake()->recording();
    TrackExecutionJob::$executionOrder = [];

    $context = new SimpleContext;
    $context->name = 'left';

    Pipeline::make([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'left' => TrackExecutionJobB::class,
            'right' => TrackExecutionJobC::class,
        ]),
    ])->send($context)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]);
});
