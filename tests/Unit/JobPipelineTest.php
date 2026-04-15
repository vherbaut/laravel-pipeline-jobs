<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PendingPipelineDispatch;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
});

it('returns a PipelineBuilder instance from make()', function () {
    $builder = JobPipeline::make([FakeJobA::class]);

    expect($builder)->toBeInstanceOf(PipelineBuilder::class);
});

it('returns an empty PipelineBuilder when make() is called with no args', function () {
    $builder = JobPipeline::make();

    expect($builder)->toBeInstanceOf(PipelineBuilder::class);
});

it('preserves step order from make() input array', function () {
    $builder = JobPipeline::make([FakeJobA::class, FakeJobB::class, FakeJobC::class]);

    $definition = $builder->build();

    expect($definition->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobC::class);
});

it('returns a PendingPipelineDispatch instance from dispatch()', function (): void {
    $wrapper = JobPipeline::dispatch([]);

    expect($wrapper)->toBeInstanceOf(PendingPipelineDispatch::class);

    // Cancel on empty-builder wrapper (would throw InvalidPipelineDefinition via run()).
    $wrapper->cancel();
});

it('accepts an empty array via dispatch()', function (): void {
    $wrapper = JobPipeline::dispatch();

    expect($wrapper)->toBeInstanceOf(PendingPipelineDispatch::class);

    $wrapper->cancel();
});

it('threads a job class-string to the underlying builder through dispatch()', function (): void {
    $wrapper = JobPipeline::dispatch([TrackExecutionJobA::class]);

    unset($wrapper);

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);
});

it('threads a pre-built StepDefinition with retry config through dispatch()', function (): void {
    $step = Step::make(TrackExecutionJobA::class)->retry(3);

    $wrapper = JobPipeline::dispatch([$step]);

    $builderProperty = (new ReflectionClass($wrapper))->getProperty('builder');
    $builder = $builderProperty->getValue($wrapper);
    expect($builder)->toBeInstanceOf(PipelineBuilder::class);

    $definition = $builder->build();
    expect($definition->steps[0]->retry)->toBe(3)
        ->and($definition->steps[0]->jobClass)->toBe(TrackExecutionJobA::class);

    // Let destruct run; TrackExecutionJobA is harmless.
    unset($wrapper);
});

it('surfaces InvalidPipelineDefinition from dispatch() on non-class-string items', function (): void {
    expect(fn () => JobPipeline::dispatch([123]))->toThrow(InvalidPipelineDefinition::class);
});
