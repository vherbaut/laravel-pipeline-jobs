<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Testing\FakePipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Testing\PipelineFake;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
});

it('swaps the container binding when fake() is called', function (): void {
    Pipeline::fake();

    expect(app(JobPipeline::class))->toBeInstanceOf(PipelineFake::class);
});

it('records pipeline executions without running jobs', function (): void {
    Pipeline::fake();

    Pipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])->run();

    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
});

it('stores recorded pipelines as PipelineDefinition objects', function (): void {
    $fake = Pipeline::fake();

    Pipeline::make([FakeJobA::class, FakeJobB::class])->run();

    $recorded = $fake->recordedPipelines();

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0])->toBeInstanceOf(PipelineDefinition::class);
});

it('records multiple pipeline executions independently', function (): void {
    $fake = Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();
    Pipeline::make([FakeJobB::class])->run();

    expect($fake->recordedPipelines())->toHaveCount(2);
});

it('clears recorded pipelines on reset()', function (): void {
    $fake = Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();

    expect($fake->recordedPipelines())->toHaveCount(1);

    $fake->reset();

    expect($fake->recordedPipelines())->toBeEmpty();
});

it('returns the PipelineFake instance from fake()', function (): void {
    $fake = Pipeline::fake();

    expect($fake)->toBeInstanceOf(PipelineFake::class);
});

// --- PipelineAssertions tests ---

it('assertPipelineRan passes when a pipeline was dispatched', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();

    Pipeline::assertPipelineRan();
});

it('assertPipelineRan fails when no pipeline was dispatched', function (): void {
    Pipeline::fake();

    expect(fn () => Pipeline::assertPipelineRan())
        ->toThrow(ExpectationFailedException::class);
});

it('assertPipelineRan with callback filters by custom logic', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();

    Pipeline::assertPipelineRan(fn (PipelineDefinition $definition) => $definition->steps[0]->jobClass === FakeJobA::class);
});

it('assertPipelineRan with callback fails when no match', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();

    expect(fn () => Pipeline::assertPipelineRan(fn (PipelineDefinition $definition) => $definition->steps[0]->jobClass === FakeJobB::class))
        ->toThrow(ExpectationFailedException::class);
});

it('assertPipelineRanWith passes with exact job match', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class, FakeJobB::class])->run();

    Pipeline::assertPipelineRanWith([FakeJobA::class, FakeJobB::class]);
});

it('assertPipelineRanWith fails with wrong order', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class, FakeJobB::class])->run();

    expect(fn () => Pipeline::assertPipelineRanWith([FakeJobB::class, FakeJobA::class]))
        ->toThrow(ExpectationFailedException::class);
});

it('assertPipelineRanWith fails with missing job', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();

    expect(fn () => Pipeline::assertPipelineRanWith([FakeJobA::class, FakeJobB::class]))
        ->toThrow(ExpectationFailedException::class);
});

it('assertNoPipelinesRan passes when empty', function (): void {
    Pipeline::fake();

    Pipeline::assertNoPipelinesRan();
});

it('assertNoPipelinesRan fails when pipelines exist', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();

    expect(fn () => Pipeline::assertNoPipelinesRan())
        ->toThrow(ExpectationFailedException::class);
});

it('assertPipelineRanTimes passes with correct count', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();
    Pipeline::make([FakeJobB::class])->run();

    Pipeline::assertPipelineRanTimes(2);
});

it('assertPipelineRanTimes fails with incorrect count', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();

    expect(fn () => Pipeline::assertPipelineRanTimes(2))
        ->toThrow(ExpectationFailedException::class);
});

// --- Facade swap & API surface tests ---

it('make() returns a FakePipelineBuilder after faking', function (): void {
    Pipeline::fake();

    $builder = Pipeline::make([FakeJobA::class]);

    expect($builder)->toBeInstanceOf(FakePipelineBuilder::class);
});

it('fluent step() API works with FakePipelineBuilder', function (): void {
    Pipeline::fake();

    Pipeline::make()->step(FakeJobA::class)->step(FakeJobB::class)->run();

    Pipeline::assertPipelineRanWith([FakeJobA::class, FakeJobB::class]);
});

it('listen() records the pipeline without registering an event listener', function (): void {
    Pipeline::fake();

    Pipeline::listen('App\\Events\\SomeEvent', [FakeJobA::class, FakeJobB::class]);

    Pipeline::assertPipelineRan();
    Pipeline::assertPipelineRanWith([FakeJobA::class, FakeJobB::class]);
});

it('send() records context without executing', function (): void {
    Pipeline::fake();

    $context = new SimpleContext;
    Pipeline::make([FakeJobA::class])->send($context)->run();

    Pipeline::assertPipelineRan();
    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
});

it('toListener() records pipeline and returns a no-op closure', function (): void {
    Pipeline::fake();

    $listener = Pipeline::make([FakeJobA::class, FakeJobB::class])->toListener();

    expect($listener)->toBeInstanceOf(Closure::class);

    // Invoking the listener should not execute anything
    $listener(new stdClass);

    Pipeline::assertPipelineRan();
    expect(TrackExecutionJob::$executionOrder)->toBeEmpty();
});
