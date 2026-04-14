<?php

declare(strict_types=1);

use Laravel\SerializableClosure\SerializableClosure;
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

it('preserves serializable failure-context fields through serialization round-trip', function () {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne', 'App\\Jobs\\StepTwo'],
    );

    // Throwable is intentionally never serialized onto queue payloads (Story 5.2 Task 2.4),
    // so we assert only the scalar failure-context fields round-trip cleanly.
    $manifest->failedStepClass = 'App\\Jobs\\StepTwo';
    $manifest->failedStepIndex = 1;

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->failedStepClass)->toBe('App\\Jobs\\StepTwo')
        ->and($restored->failedStepIndex)->toBe(1)
        ->and($restored->failureException)->toBeNull();
});

it('preserves beforeEach hooks through SerializableClosure round-trip', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);
    $manifest->beforeEachHooks = [new SerializableClosure(fn () => 'before-value')];

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->beforeEachHooks)->toHaveCount(1)
        ->and($restored->beforeEachHooks[0])->toBeInstanceOf(SerializableClosure::class)
        ->and(($restored->beforeEachHooks[0]->getClosure())())->toBe('before-value');
});

it('preserves afterEach hooks through SerializableClosure round-trip', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);
    $manifest->afterEachHooks = [new SerializableClosure(fn () => 'after-value')];

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->afterEachHooks)->toHaveCount(1)
        ->and($restored->afterEachHooks[0])->toBeInstanceOf(SerializableClosure::class)
        ->and(($restored->afterEachHooks[0]->getClosure())())->toBe('after-value');
});

it('preserves onStepFailed hooks through SerializableClosure round-trip', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);
    $manifest->onStepFailedHooks = [new SerializableClosure(fn () => 'failed-value')];

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->onStepFailedHooks)->toHaveCount(1)
        ->and($restored->onStepFailedHooks[0])->toBeInstanceOf(SerializableClosure::class)
        ->and(($restored->onStepFailedHooks[0]->getClosure())())->toBe('failed-value');
});

it('restores empty hook arrays when deserializing a legacy payload without hook keys', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);

    // Emulate a legacy queue payload from before Story 6.1 (no hook keys).
    $legacyPayload = [
        'pipelineId' => $manifest->pipelineId,
        'pipelineName' => null,
        'stepClasses' => ['App\\Jobs\\Step'],
        'compensationMapping' => [],
        'stepConditions' => [],
        'currentStepIndex' => 0,
        'completedSteps' => [],
        'context' => null,
        'failStrategy' => $manifest->failStrategy,
        'failedStepClass' => null,
        'failedStepIndex' => null,
    ];

    // PHP allows setting readonly properties inside __unserialize only on a
    // freshly allocated (uninitialized) instance, matching the codepath used
    // by unserialize() internally. Use newInstanceWithoutConstructor() to
    // reach that state without invoking the real constructor.
    $restored = (new ReflectionClass(PipelineManifest::class))->newInstanceWithoutConstructor();
    $restored->__unserialize($legacyPayload);

    expect($restored->beforeEachHooks)->toBe([])
        ->and($restored->afterEachHooks)->toBe([])
        ->and($restored->onStepFailedHooks)->toBe([]);
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
