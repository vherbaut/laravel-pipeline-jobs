<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('preserves step list through serialization round-trip', function () {
    $stepClasses = ['App\\Jobs\\StepOne', 'App\\Jobs\\StepTwo', 'App\\Jobs\\StepThree'];
    $manifest = PipelineManifest::create(stepClasses: $stepClasses);

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->stepClasses)->toBe($stepClasses);
});

it('preserves current step index through serialization round-trip', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A', 'App\\Jobs\\B', 'App\\Jobs\\C'],
    );

    $manifest->advanceStep();
    $manifest->advanceStep();

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->currentStepIndex)->toBe(2);
});

it('preserves completed steps through serialization round-trip', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A', 'App\\Jobs\\B'],
    );

    $manifest->markStepCompleted('App\\Jobs\\A');
    $manifest->markStepCompleted('App\\Jobs\\B');

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->completedSteps)->toBe(['App\\Jobs\\A', 'App\\Jobs\\B']);
});

it('preserves pipeline ID through serialization round-trip', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
    );

    $originalId = $manifest->pipelineId;

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->pipelineId)->toBe($originalId);
});

it('preserves pipeline name through serialization round-trip', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        pipelineName: 'order-pipeline',
    );

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->pipelineName)->toBe('order-pipeline');
});

it('preserves compensation mapping through serialization round-trip', function () {
    $mapping = [
        'App\\Jobs\\Charge' => 'App\\Jobs\\Refund',
        'App\\Jobs\\Ship' => 'App\\Jobs\\CancelShipment',
    ];

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\Charge', 'App\\Jobs\\Ship'],
        compensationMapping: $mapping,
    );

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->compensationMapping)->toBe($mapping);
});

it('preserves PipelineContext through serialization round-trip', function () {
    $context = new SimpleContext;
    $context->name = 'test-pipeline';
    $context->count = 42;
    $context->active = true;
    $context->tags = ['foo', 'bar'];

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        context: $context,
    );

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->context)
        ->toBeInstanceOf(SimpleContext::class)
        ->and($restored->context->name)->toBe('test-pipeline')
        ->and($restored->context->count)->toBe(42)
        ->and($restored->context->active)->toBeTrue()
        ->and($restored->context->tags)->toBe(['foo', 'bar']);
});
