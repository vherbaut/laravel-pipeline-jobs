<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\IncrementCountJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
});

// --- 9.2: Realistic recording scenario ---

it('records step execution and context snapshots in a realistic 3-step pipeline', function (): void {
    Pipeline::fake()->recording();

    $context = new SimpleContext;
    $context->name = 'start';

    Pipeline::make([
        EnrichContextJob::class,
        IncrementCountJob::class,
        ReadContextJob::class,
    ])->send($context)->run();

    // Step execution order
    Pipeline::assertStepsExecutedInOrder([
        EnrichContextJob::class,
        IncrementCountJob::class,
        ReadContextJob::class,
    ]);

    // Per-step context inspection
    $afterEnrich = Pipeline::getContextAfterStep(EnrichContextJob::class);
    expect($afterEnrich)->toBeInstanceOf(SimpleContext::class)
        ->and($afterEnrich->name)->toBe('enriched')
        ->and($afterEnrich->count)->toBe(0);

    $afterIncrement = Pipeline::getContextAfterStep(IncrementCountJob::class);
    expect($afterIncrement->name)->toBe('enriched')
        ->and($afterIncrement->count)->toBe(1);

    $afterRead = Pipeline::getContextAfterStep(ReadContextJob::class);
    expect($afterRead->name)->toBe('enriched')
        ->and($afterRead->count)->toBe(1);

    // Final context
    Pipeline::assertContextHas('name', 'enriched');
    Pipeline::assertContextHas('count', 1);
    Pipeline::assertContext(fn (PipelineContext $ctx): bool => $ctx instanceof SimpleContext && $ctx->name === 'enriched');
});

// --- 9.3: Fake without recording ---

it('records sent context without execution in fake mode', function (): void {
    Pipeline::fake();

    $context = new SimpleContext;
    $context->name = 'test-value';
    $context->count = 42;

    Pipeline::make([EnrichContextJob::class, IncrementCountJob::class])
        ->send($context)
        ->run();

    // Pipeline was recorded
    Pipeline::assertPipelineRan();
    Pipeline::assertPipelineRanWith([EnrichContextJob::class, IncrementCountJob::class]);

    // Context was recorded but NOT modified (no execution)
    $recorded = Pipeline::getRecordedContext();
    expect($recorded)->toBeInstanceOf(SimpleContext::class)
        ->and($recorded->name)->toBe('test-value')
        ->and($recorded->count)->toBe(42);

    // No jobs actually executed
    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
    expect(ReadContextJob::$readName)->toBeNull();
});

// --- 9.4: Both API entry points ---

it('works with array make([...]) API', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class, IncrementCountJob::class])
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertStepExecuted(EnrichContextJob::class);
    Pipeline::assertStepExecuted(IncrementCountJob::class);
    Pipeline::assertContextHas('name', 'enriched');
    Pipeline::assertContextHas('count', 1);
});

it('works with fluent step() API', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(EnrichContextJob::class)
        ->step(IncrementCountJob::class)
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertStepExecuted(EnrichContextJob::class);
    Pipeline::assertStepExecuted(IncrementCountJob::class);
    Pipeline::assertContextHas('name', 'enriched');
    Pipeline::assertContextHas('count', 1);
});

// --- 9.5: Multiple pipeline runs with recording ---

it('asserts on specific pipeline by index with multiple recording runs', function (): void {
    Pipeline::fake()->recording();

    // First pipeline: EnrichContextJob only
    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    // Second pipeline: IncrementCountJob only
    $ctx2 = new SimpleContext;
    $ctx2->count = 10;
    Pipeline::make([IncrementCountJob::class])
        ->send($ctx2)
        ->run();

    // Assert on first pipeline (index 0)
    Pipeline::assertStepExecuted(EnrichContextJob::class, 0);
    Pipeline::assertStepNotExecuted(IncrementCountJob::class, 0);
    Pipeline::assertContextHas('name', 'enriched', 0);

    // Assert on second pipeline (index 1)
    Pipeline::assertStepExecuted(IncrementCountJob::class, 1);
    Pipeline::assertStepNotExecuted(EnrichContextJob::class, 1);
    Pipeline::assertContextHas('count', 11, 1);

    // Assert on most recent (no index = last)
    Pipeline::assertStepExecuted(IncrementCountJob::class);

    Pipeline::assertPipelineRanTimes(2);
});

// --- 9.6: Zero regression verification ---

it('all existing assertion methods still work with the RecordedPipeline refactor', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class, FakeJobB::class, FakeJobC::class])->run();

    Pipeline::assertPipelineRan();
    Pipeline::assertPipelineRanWith([FakeJobA::class, FakeJobB::class, FakeJobC::class]);
    Pipeline::assertPipelineRanTimes(1);

    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
});

it('recording mode returns the final context from run()', function (): void {
    Pipeline::fake()->recording();

    $result = Pipeline::make([EnrichContextJob::class, IncrementCountJob::class])
        ->send(new SimpleContext)
        ->run();

    expect($result)->toBeInstanceOf(SimpleContext::class)
        ->and($result->name)->toBe('enriched')
        ->and($result->count)->toBe(1);
});

it('fake mode returns null from run()', function (): void {
    Pipeline::fake();

    $result = Pipeline::make([FakeJobA::class])->send(new SimpleContext)->run();

    expect($result)->toBeNull();
});
