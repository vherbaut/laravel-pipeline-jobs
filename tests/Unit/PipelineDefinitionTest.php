<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\NestedPipeline;
use Vherbaut\LaravelPipelineJobs\ParallelStepGroup;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

it('can be created from a list of StepDefinitions', function () {
    $steps = [
        StepDefinition::fromJobClass('App\\Jobs\\StepOne'),
        StepDefinition::fromJobClass('App\\Jobs\\StepTwo'),
    ];

    $definition = new PipelineDefinition(steps: $steps);

    expect($definition->steps)->toHaveCount(2)
        ->and($definition->steps[0]->jobClass)->toBe('App\\Jobs\\StepOne')
        ->and($definition->steps[1]->jobClass)->toBe('App\\Jobs\\StepTwo');
});

it('exposes ordered step list and pipeline config', function () {
    $steps = [
        StepDefinition::fromJobClass('App\\Jobs\\First'),
        StepDefinition::fromJobClass('App\\Jobs\\Second'),
        StepDefinition::fromJobClass('App\\Jobs\\Third'),
    ];

    $onComplete = fn () => null;

    $definition = new PipelineDefinition(
        steps: $steps,
        shouldBeQueued: true,
        name: 'order-pipeline',
        onComplete: $onComplete,
        failStrategy: FailStrategy::SkipAndContinue,
    );

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0]->jobClass)->toBe('App\\Jobs\\First')
        ->and($definition->shouldBeQueued)->toBeTrue()
        ->and($definition->name)->toBe('order-pipeline')
        ->and($definition->onComplete)->toBe($onComplete)
        ->and($definition->failStrategy)->toBe(FailStrategy::SkipAndContinue);
});

it('is immutable with readonly properties', function () {
    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass('App\\Jobs\\Step')],
    );

    $reflection = new ReflectionClass($definition);

    foreach ($reflection->getProperties() as $property) {
        expect($property->isReadOnly())->toBeTrue(
            "Property \"{$property->getName()}\" should be readonly"
        );
    }
});

it('throws InvalidPipelineDefinition when created with empty steps', function () {
    new PipelineDefinition(steps: []);
})->throws(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');

it('returns correct step count', function () {
    $definition = new PipelineDefinition(
        steps: [
            StepDefinition::fromJobClass('App\\Jobs\\A'),
            StepDefinition::fromJobClass('App\\Jobs\\B'),
            StepDefinition::fromJobClass('App\\Jobs\\C'),
        ],
    );

    expect($definition->stepCount())->toBe(3);
});

it('defaults hook arrays to empty and callbacks to null', function () {
    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass('App\\Jobs\\Step')],
    );

    expect($definition->beforeEachHooks)->toBe([])
        ->and($definition->afterEachHooks)->toBe([])
        ->and($definition->onStepFailedHooks)->toBe([])
        ->and($definition->onComplete)->toBeNull()
        ->and($definition->onSuccess)->toBeNull()
        ->and($definition->onFailure)->toBeNull()
        ->and($definition->shouldBeQueued)->toBeFalse()
        ->and($definition->name)->toBeNull()
        ->and($definition->failStrategy)->toBe(FailStrategy::StopImmediately);
});

it('stores beforeEach/afterEach/onStepFailed hooks passed at construction', function () {
    $before = fn () => null;
    $after = fn () => null;
    $failed = fn () => null;

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass('App\\Jobs\\Step')],
        beforeEachHooks: [$before],
        afterEachHooks: [$after],
        onStepFailedHooks: [$failed],
    );

    expect($definition->beforeEachHooks)->toBe([$before])
        ->and($definition->afterEachHooks)->toBe([$after])
        ->and($definition->onStepFailedHooks)->toBe([$failed]);
});

it('stores onSuccess/onFailure/onComplete callbacks passed at construction', function () {
    $onSuccess = fn () => null;
    $onFailure = fn () => null;
    $onComplete = fn () => null;

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass('App\\Jobs\\Step')],
        onComplete: $onComplete,
        onSuccess: $onSuccess,
        onFailure: $onFailure,
    );

    expect($definition->onSuccess)->toBe($onSuccess)
        ->and($definition->onFailure)->toBe($onFailure)
        ->and($definition->onComplete)->toBe($onComplete);
});

it('defaults retry/backoff/timeout pipeline-wide defaults to null', function () {
    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass('App\\Jobs\\Step')],
    );

    expect($definition->defaultRetry)->toBeNull()
        ->and($definition->defaultBackoff)->toBeNull()
        ->and($definition->defaultTimeout)->toBeNull();
});

it('stores pipeline-wide defaultRetry/defaultBackoff/defaultTimeout passed at construction', function () {
    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass('App\\Jobs\\Step')],
        defaultRetry: 2,
        defaultBackoff: 5,
        defaultTimeout: 60,
    );

    expect($definition->defaultRetry)->toBe(2)
        ->and($definition->defaultBackoff)->toBe(5)
        ->and($definition->defaultTimeout)->toBe(60);
});

// --- ParallelStepGroup support (Story 8.1) ---

it('stepCount counts a parallel group as one outer position', function (): void {
    $group = ParallelStepGroup::fromArray([
        'App\\Jobs\\SubA',
        'App\\Jobs\\SubB',
        'App\\Jobs\\SubC',
    ]);

    $definition = new PipelineDefinition(steps: [
        StepDefinition::fromJobClass('App\\Jobs\\StepA'),
        $group,
        StepDefinition::fromJobClass('App\\Jobs\\StepD'),
    ]);

    expect($definition->stepCount())->toBe(3);
});

it('flatStepCount expands a parallel group to its sub-step count', function (): void {
    $group = ParallelStepGroup::fromArray([
        'App\\Jobs\\SubA',
        'App\\Jobs\\SubB',
        'App\\Jobs\\SubC',
    ]);

    $definition = new PipelineDefinition(steps: [
        StepDefinition::fromJobClass('App\\Jobs\\StepA'),
        $group,
        StepDefinition::fromJobClass('App\\Jobs\\StepD'),
    ]);

    expect($definition->flatStepCount())->toBe(5);
});

it('compensationMapping includes sub-step compensations from a parallel group', function (): void {
    $stepWithCompensation = StepDefinition::fromJobClass('App\\Jobs\\SubA')
        ->withCompensation('App\\Jobs\\CompensateSubA');

    $group = ParallelStepGroup::fromArray([
        $stepWithCompensation,
        'App\\Jobs\\SubB',
    ]);

    $definition = new PipelineDefinition(steps: [
        StepDefinition::fromJobClass('App\\Jobs\\StepA')->withCompensation('App\\Jobs\\CompensateA'),
        $group,
    ]);

    $mapping = $definition->compensationMapping();

    expect($mapping)->toHaveKey('App\\Jobs\\StepA')
        ->and($mapping['App\\Jobs\\StepA'])->toBe('App\\Jobs\\CompensateA')
        ->and($mapping)->toHaveKey('App\\Jobs\\SubA')
        ->and($mapping['App\\Jobs\\SubA'])->toBe('App\\Jobs\\CompensateSubA')
        ->and($mapping)->not->toHaveKey('App\\Jobs\\SubB');
});

// --- Story 8.2: NestedPipeline integration -------------------------------------------------

it('stepCount counts a nested group as a single outer position', function (): void {
    $inner = JobPipeline::make(['App\\Jobs\\InnerA', 'App\\Jobs\\InnerB', 'App\\Jobs\\InnerC']);
    $nested = NestedPipeline::fromBuilder($inner);

    $definition = new PipelineDefinition(
        steps: [
            StepDefinition::fromJobClass('App\\Jobs\\OuterA'),
            $nested,
            StepDefinition::fromJobClass('App\\Jobs\\OuterB'),
        ],
    );

    expect($definition->stepCount())->toBe(3);
});

it('flatStepCount expands a nested group to its recursive inner step count', function (): void {
    $inner = JobPipeline::make(['App\\Jobs\\InnerA', 'App\\Jobs\\InnerB']);
    $nested = NestedPipeline::fromBuilder($inner);

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass('App\\Jobs\\OuterA'), $nested],
    );

    expect($definition->flatStepCount())->toBe(3);
});

it('flatStepCount expands a nested+parallel mix', function (): void {
    $inner = JobPipeline::make(['App\\Jobs\\InnerA'])
        ->parallel(['App\\Jobs\\InnerP1', 'App\\Jobs\\InnerP2']);
    $nested = NestedPipeline::fromBuilder($inner);

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass('App\\Jobs\\Outer'), $nested],
    );

    // Outer(1) + inner InnerA(1) + parallel(2) → 4
    expect($definition->flatStepCount())->toBe(4);
});

it('flatStepCount expands three-level nested groups transitively', function (): void {
    $innermost = JobPipeline::make(['App\\Jobs\\DeepA', 'App\\Jobs\\DeepB']);
    $middle = JobPipeline::make(['App\\Jobs\\MidA'])->nest($innermost);
    $outerNested = NestedPipeline::fromBuilder($middle);

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass('App\\Jobs\\Top'), $outerNested],
    );

    // Top(1) + MidA(1) + DeepA(1) + DeepB(1) → 4
    expect($definition->flatStepCount())->toBe(4);
});

it('compensationMapping merges inner-and-outer compensations across nested groups', function (): void {
    $inner = JobPipeline::make([
        'App\\Jobs\\InnerA',
    ])->compensateWith('App\\Jobs\\InnerACompensate');

    $nested = NestedPipeline::fromBuilder($inner);

    $outerStep = StepDefinition::fromJobClass('App\\Jobs\\OuterA')
        ->withCompensation('App\\Jobs\\OuterACompensate');

    $definition = new PipelineDefinition(steps: [$outerStep, $nested]);

    $mapping = $definition->compensationMapping();

    expect($mapping)->toBe([
        'App\\Jobs\\OuterA' => 'App\\Jobs\\OuterACompensate',
        'App\\Jobs\\InnerA' => 'App\\Jobs\\InnerACompensate',
    ]);
});

it('compensationMapping later-key-wins when an inner entry duplicates an outer class', function (): void {
    $inner = JobPipeline::make([
        'App\\Jobs\\SharedJob',
    ])->compensateWith('App\\Jobs\\InnerSharedCompensate');

    $nested = NestedPipeline::fromBuilder($inner);

    $outerShared = StepDefinition::fromJobClass('App\\Jobs\\SharedJob')
        ->withCompensation('App\\Jobs\\OuterSharedCompensate');

    $definition = new PipelineDefinition(steps: [$outerShared, $nested]);

    // array_merge with later-key-wins: the nested's mapping is merged LAST, so inner wins.
    expect($definition->compensationMapping())->toBe([
        'App\\Jobs\\SharedJob' => 'App\\Jobs\\InnerSharedCompensate',
    ]);
});
