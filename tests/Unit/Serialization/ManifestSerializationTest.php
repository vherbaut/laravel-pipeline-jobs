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

it('preserves onSuccessCallback through SerializableClosure round-trip', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);
    $manifest->onSuccessCallback = new SerializableClosure(fn () => 'success-value');

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->onSuccessCallback)->toBeInstanceOf(SerializableClosure::class)
        ->and(($restored->onSuccessCallback->getClosure())())->toBe('success-value');
});

it('preserves onFailureCallback through SerializableClosure round-trip', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);
    $manifest->onFailureCallback = new SerializableClosure(fn () => 'failure-value');

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->onFailureCallback)->toBeInstanceOf(SerializableClosure::class)
        ->and(($restored->onFailureCallback->getClosure())())->toBe('failure-value');
});

it('preserves onCompleteCallback through SerializableClosure round-trip', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);
    $manifest->onCompleteCallback = new SerializableClosure(fn () => 'complete-value');

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->onCompleteCallback)->toBeInstanceOf(SerializableClosure::class)
        ->and(($restored->onCompleteCallback->getClosure())())->toBe('complete-value');
});

it('restores null callback slots when deserializing a legacy payload without callback keys', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);

    // Emulate a legacy payload from before Story 6.2 (no callback keys).
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
        'beforeEachHooks' => [],
        'afterEachHooks' => [],
        'onStepFailedHooks' => [],
    ];

    $restored = (new ReflectionClass(PipelineManifest::class))->newInstanceWithoutConstructor();
    $restored->__unserialize($legacyPayload);

    expect($restored->onSuccessCallback)->toBeNull()
        ->and($restored->onFailureCallback)->toBeNull()
        ->and($restored->onCompleteCallback)->toBeNull();
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

it('preserves stepConfigs through serialization round-trip', function () {
    $stepConfigs = [
        0 => ['queue' => 'heavy', 'connection' => 'redis', 'sync' => false, 'retry' => 3, 'backoff' => 5, 'timeout' => 60],
        1 => ['queue' => null, 'connection' => null, 'sync' => true, 'retry' => null, 'backoff' => null, 'timeout' => null],
        2 => ['queue' => 'background', 'connection' => null, 'sync' => false, 'retry' => 1, 'backoff' => null, 'timeout' => 30],
    ];

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne', 'App\\Jobs\\StepTwo', 'App\\Jobs\\StepThree'],
        stepConfigs: $stepConfigs,
    );

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->stepConfigs)->toBe($stepConfigs);
});

it('preserves pre-Story-7.2 three-key stepConfigs shape through deserialization for backward compatibility', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);

    // Legacy payload shape from Story 7.1 (three-key stepConfigs without retry/backoff/timeout).
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
        'beforeEachHooks' => [],
        'afterEachHooks' => [],
        'onStepFailedHooks' => [],
        'onSuccessCallback' => null,
        'onFailureCallback' => null,
        'onCompleteCallback' => null,
        'stepConfigs' => [
            0 => ['queue' => 'heavy', 'connection' => 'redis', 'sync' => false],
        ],
    ];

    $restored = (new ReflectionClass(PipelineManifest::class))->newInstanceWithoutConstructor();
    $restored->__unserialize($legacyPayload);

    // Legacy three-key entries survive as-is; downstream consumers use `?? null` to degrade to no-retry / no-timeout.
    expect($restored->stepConfigs[0])->toBe(['queue' => 'heavy', 'connection' => 'redis', 'sync' => false])
        ->and($restored->stepConfigs[0]['retry'] ?? null)->toBeNull()
        ->and($restored->stepConfigs[0]['backoff'] ?? null)->toBeNull()
        ->and($restored->stepConfigs[0]['timeout'] ?? null)->toBeNull();
});

it('serializes a manifest containing a parallel-group shape', function () {
    $stepClasses = [
        'App\\Jobs\\StepA',
        ['type' => 'parallel', 'classes' => ['App\\Jobs\\StepB', 'App\\Jobs\\StepC']],
        'App\\Jobs\\StepD',
    ];

    $manifest = PipelineManifest::create(stepClasses: $stepClasses);

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->stepClasses)->toBe($stepClasses);
});

it('serializes a manifest containing parallel stepConditions and stepConfigs shapes', function () {
    $stepConditions = [
        1 => [
            'type' => 'parallel',
            'entries' => [
                0 => ['closure' => new SerializableClosure(fn () => true), 'negated' => false],
                1 => null,
            ],
        ],
    ];
    $stepConfigs = [
        0 => ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
        1 => [
            'type' => 'parallel',
            'configs' => [
                0 => ['queue' => 'fast', 'connection' => 'redis', 'sync' => false, 'retry' => 2, 'backoff' => 5, 'timeout' => 30],
                1 => ['queue' => null, 'connection' => null, 'sync' => true, 'retry' => null, 'backoff' => null, 'timeout' => null],
            ],
        ],
    ];
    $stepClasses = [
        'App\\Jobs\\Outer',
        ['type' => 'parallel', 'classes' => ['App\\Jobs\\Sub1', 'App\\Jobs\\Sub2']],
    ];

    $manifest = PipelineManifest::create(
        stepClasses: $stepClasses,
        stepConditions: $stepConditions,
        stepConfigs: $stepConfigs,
    );

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->stepClasses)->toBe($stepClasses)
        ->and($restored->stepConfigs)->toBe($stepConfigs)
        ->and($restored->stepConditions[1]['type'])->toBe('parallel')
        ->and($restored->stepConditions[1]['entries'][1])->toBeNull()
        ->and($restored->stepConditions[1]['entries'][0]['negated'])->toBeFalse()
        ->and(($restored->stepConditions[1]['entries'][0]['closure']->getClosure())())->toBeTrue();
});

it('deserializes a legacy all-string stepClasses payload unchanged', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);

    // Legacy payload predates Story 8.1: $stepClasses is a flat list of class-strings.
    $legacyPayload = [
        'pipelineId' => $manifest->pipelineId,
        'pipelineName' => null,
        'stepClasses' => ['App\\Jobs\\A', 'App\\Jobs\\B', 'App\\Jobs\\C'],
        'compensationMapping' => [],
        'stepConditions' => [],
        'currentStepIndex' => 0,
        'completedSteps' => [],
        'context' => null,
        'failStrategy' => $manifest->failStrategy,
        'failedStepClass' => null,
        'failedStepIndex' => null,
        'beforeEachHooks' => [],
        'afterEachHooks' => [],
        'onStepFailedHooks' => [],
        'onSuccessCallback' => null,
        'onFailureCallback' => null,
        'onCompleteCallback' => null,
        'stepConfigs' => [],
    ];

    $restored = (new ReflectionClass(PipelineManifest::class))->newInstanceWithoutConstructor();
    $restored->__unserialize($legacyPayload);

    expect($restored->stepClasses)->toBe(['App\\Jobs\\A', 'App\\Jobs\\B', 'App\\Jobs\\C']);
});

it('defaults stepConfigs to an empty array when deserializing a legacy payload', function () {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Step']);

    // Legacy payload shape predates Story 7.1 (no stepConfigs key).
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
        'beforeEachHooks' => [],
        'afterEachHooks' => [],
        'onStepFailedHooks' => [],
        'onSuccessCallback' => null,
        'onFailureCallback' => null,
        'onCompleteCallback' => null,
    ];

    $restored = (new ReflectionClass(PipelineManifest::class))->newInstanceWithoutConstructor();
    $restored->__unserialize($legacyPayload);

    expect($restored->stepConfigs)->toBe([]);
});
