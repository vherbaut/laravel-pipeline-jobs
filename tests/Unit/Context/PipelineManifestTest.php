<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('can be created with step list and initial state', function () {
    $stepClasses = ['App\\Jobs\\StepOne', 'App\\Jobs\\StepTwo'];

    $manifest = PipelineManifest::create(stepClasses: $stepClasses);

    expect($manifest->stepClasses)->toBe($stepClasses)
        ->and($manifest->currentStepIndex)->toBe(0)
        ->and($manifest->completedSteps)->toBe([])
        ->and($manifest->context)->toBeNull()
        ->and($manifest->compensationMapping)->toBe([])
        ->and($manifest->pipelineName)->toBeNull();
});

it('generates a UUID pipeline ID', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
    );

    expect($manifest->pipelineId)
        ->toBeString()
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('starts with current step index at 0 and empty completed steps', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A', 'App\\Jobs\\B', 'App\\Jobs\\C'],
    );

    expect($manifest->currentStepIndex)->toBe(0)
        ->and($manifest->completedSteps)->toBe([]);
});

it('can advance the step index', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A', 'App\\Jobs\\B'],
    );

    $manifest->advanceStep();

    expect($manifest->currentStepIndex)->toBe(1);

    $manifest->advanceStep();

    expect($manifest->currentStepIndex)->toBe(2);
});

it('can mark a step as completed', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A', 'App\\Jobs\\B'],
    );

    $manifest->markStepCompleted('App\\Jobs\\A');

    expect($manifest->completedSteps)->toBe(['App\\Jobs\\A']);

    $manifest->markStepCompleted('App\\Jobs\\B');

    expect($manifest->completedSteps)->toBe(['App\\Jobs\\A', 'App\\Jobs\\B']);
});

it('can set and retrieve the pipeline context', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
    );

    $context = new SimpleContext;
    $context->name = 'test';

    $manifest->setContext($context);

    expect($manifest->context)
        ->toBeInstanceOf(PipelineContext::class)
        ->toBeInstanceOf(SimpleContext::class)
        ->and($manifest->context->name)->toBe('test');
});

it('stores compensation mapping correctly', function () {
    $mapping = [
        'App\\Jobs\\Charge' => 'App\\Jobs\\Refund',
        'App\\Jobs\\Ship' => 'App\\Jobs\\CancelShipment',
    ];

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\Charge', 'App\\Jobs\\Ship'],
        compensationMapping: $mapping,
    );

    expect($manifest->compensationMapping)->toBe($mapping);
});

it('accepts optional pipeline name', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        pipelineName: 'order-processing',
    );

    expect($manifest->pipelineName)->toBe('order-processing');
});

it('defaults failStrategy to StopImmediately on both constructor and create()', function () {
    $viaCreate = PipelineManifest::create(stepClasses: ['App\\Jobs\\StepOne']);

    $viaConstructor = new PipelineManifest(
        pipelineId: 'fake-uuid',
        pipelineName: null,
        stepClasses: ['App\\Jobs\\StepOne'],
        compensationMapping: [],
        stepConditions: [],
        currentStepIndex: 0,
        completedSteps: [],
        context: null,
    );

    expect($viaCreate->failStrategy)->toBe(FailStrategy::StopImmediately)
        ->and($viaConstructor->failStrategy)->toBe(FailStrategy::StopImmediately);
});

it('stores and exposes the passed failStrategy via create()', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        failStrategy: FailStrategy::StopAndCompensate,
    );

    expect($manifest->failStrategy)->toBe(FailStrategy::StopAndCompensate);
});

it('round-trips failStrategy through serialization', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        failStrategy: FailStrategy::StopAndCompensate,
    );

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->failStrategy)->toBe(FailStrategy::StopAndCompensate);
});

it('defaults failureException, failedStepClass, failedStepIndex to null', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\StepOne']);

    expect($manifest->failureException)->toBeNull()
        ->and($manifest->failedStepClass)->toBeNull()
        ->and($manifest->failedStepIndex)->toBeNull();
});
