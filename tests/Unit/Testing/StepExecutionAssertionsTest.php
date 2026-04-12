<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Testing\RecordedPipeline;
use Vherbaut\LaravelPipelineJobs\Testing\RecordingExecutor;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\IncrementCountJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
});

// --- 8.2: assertStepExecuted ---

it('assertStepExecuted passes when step was executed', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class, ReadContextJob::class])
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertStepExecuted(EnrichContextJob::class);
    Pipeline::assertStepExecuted(ReadContextJob::class);
});

it('assertStepExecuted fails when step was not executed', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertStepExecuted(ReadContextJob::class))
        ->toThrow(ExpectationFailedException::class);
});

// --- 8.3: assertStepNotExecuted ---

it('assertStepNotExecuted passes when step absent', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertStepNotExecuted(ReadContextJob::class);
});

it('assertStepNotExecuted fails when step present', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertStepNotExecuted(EnrichContextJob::class))
        ->toThrow(ExpectationFailedException::class);
});

// --- 8.4: assertStepsExecutedInOrder ---

it('assertStepsExecutedInOrder passes with exact match', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class, ReadContextJob::class])
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertStepsExecutedInOrder([
        EnrichContextJob::class,
        ReadContextJob::class,
    ]);
});

it('assertStepsExecutedInOrder fails with mismatch', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class, ReadContextJob::class])
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertStepsExecutedInOrder([
        ReadContextJob::class,
        EnrichContextJob::class,
    ]))->toThrow(ExpectationFailedException::class);
});

// --- 8.5: assertContextHas ---

it('assertContextHas passes with correct property value', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertContextHas('name', 'enriched');
});

it('assertContextHas fails with wrong value', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertContextHas('name', 'wrong'))
        ->toThrow(ExpectationFailedException::class);
});

// --- 8.6: assertContext ---

it('assertContext passes when callback returns true', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertContext(fn (PipelineContext $ctx): bool => $ctx instanceof SimpleContext && $ctx->name === 'enriched');
});

it('assertContext fails when callback returns false', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertContext(fn (PipelineContext $ctx): bool => false))
        ->toThrow(ExpectationFailedException::class);
});

// --- 8.7: getRecordedContext in fake mode ---

it('getRecordedContext returns the sent context in fake mode', function (): void {
    Pipeline::fake();

    $context = new SimpleContext;
    $context->name = 'original';

    Pipeline::make([FakeJobA::class])->send($context)->run();

    $recorded = Pipeline::getRecordedContext();

    expect($recorded)->toBeInstanceOf(SimpleContext::class)
        ->and($recorded->name)->toBe('original');
});

// --- 8.8: getRecordedContext in recording mode ---

it('getRecordedContext returns the final enriched context in recording mode', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    $recorded = Pipeline::getRecordedContext();

    expect($recorded)->toBeInstanceOf(SimpleContext::class)
        ->and($recorded->name)->toBe('enriched');
});

// --- 8.9: getContextAfterStep ---

it('getContextAfterStep returns the correct per-step snapshot', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class, IncrementCountJob::class])
        ->send(new SimpleContext)
        ->run();

    $afterEnrich = Pipeline::getContextAfterStep(EnrichContextJob::class);

    expect($afterEnrich)->toBeInstanceOf(SimpleContext::class)
        ->and($afterEnrich->name)->toBe('enriched')
        ->and($afterEnrich->count)->toBe(0);
});

// --- 8.10: getContextAfterStep different states for different steps ---

it('getContextAfterStep returns different states for different steps', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class, IncrementCountJob::class])
        ->send(new SimpleContext)
        ->run();

    $afterEnrich = Pipeline::getContextAfterStep(EnrichContextJob::class);
    $afterIncrement = Pipeline::getContextAfterStep(IncrementCountJob::class);

    // After EnrichContextJob: name set, count still 0
    expect($afterEnrich->name)->toBe('enriched')
        ->and($afterEnrich->count)->toBe(0);

    // After IncrementCountJob: name set, count incremented
    expect($afterIncrement->name)->toBe('enriched')
        ->and($afterIncrement->count)->toBe(1);
});

// --- 8.11: step assertions fail without recording mode ---

it('step assertions fail with clear message when called without recording mode', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();

    expect(fn () => Pipeline::assertStepExecuted(FakeJobA::class))
        ->toThrow(ExpectationFailedException::class, 'recording()');
});

// --- 8.12: RecordedPipeline stores all data ---

it('RecordedPipeline stores all data correctly', function (): void {
    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass(FakeJobA::class)],
    );
    $context = new SimpleContext;
    $executedSteps = [FakeJobA::class];
    $contextSnapshots = [clone $context];

    $recorded = new RecordedPipeline(
        definition: $definition,
        recordedContext: $context,
        executedSteps: $executedSteps,
        contextSnapshots: $contextSnapshots,
        wasRecording: true,
    );

    expect($recorded->definition)->toBe($definition)
        ->and($recorded->recordedContext)->toBe($context)
        ->and($recorded->executedSteps)->toBe($executedSteps)
        ->and($recorded->contextSnapshots)->toHaveCount(1)
        ->and($recorded->wasRecording)->toBeTrue();
});

// --- 8.13: RecordingExecutor captures data ---

it('RecordingExecutor captures per-step snapshots and executed steps', function (): void {
    $definition = new PipelineDefinition(
        steps: [
            StepDefinition::fromJobClass(EnrichContextJob::class),
            StepDefinition::fromJobClass(IncrementCountJob::class),
        ],
    );

    $context = new SimpleContext;
    $manifest = PipelineManifest::create(
        stepClasses: [EnrichContextJob::class, IncrementCountJob::class],
        context: $context,
    );

    $executor = new RecordingExecutor;
    $finalContext = $executor->execute($definition, $manifest);

    expect($executor->executedSteps())->toBe([EnrichContextJob::class, IncrementCountJob::class])
        ->and($executor->contextSnapshots())->toHaveCount(2)
        ->and($finalContext)->toBeInstanceOf(SimpleContext::class)
        ->and($finalContext->name)->toBe('enriched')
        ->and($finalContext->count)->toBe(1);

    // Verify snapshots are independent deep clones
    $snapshotAfterEnrich = $executor->contextSnapshots()[0];
    expect($snapshotAfterEnrich->name)->toBe('enriched')
        ->and($snapshotAfterEnrich->count)->toBe(0);
});

// --- Review Patch: getContextAfterStep fails when step not in snapshots ---

it('getContextAfterStep fails with clear message when step was not executed', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::getContextAfterStep(IncrementCountJob::class))
        ->toThrow(ExpectationFailedException::class, 'IncrementCountJob');
});

// --- Review Patch: resolveRecordedPipeline fails on out-of-bounds index ---

it('assertions fail with clear message when pipeline index is out of bounds', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertStepExecuted(EnrichContextJob::class, 5))
        ->toThrow(ExpectationFailedException::class, 'out of bounds');
});
