<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
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
