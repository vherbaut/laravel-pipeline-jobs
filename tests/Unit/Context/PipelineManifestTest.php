<?php

declare(strict_types=1);

use Laravel\SerializableClosure\SerializableClosure;
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

// --- Story 8.2: stepClassAt / stepConfigAt / conditionAt helpers --------------------------

it('stepClassAt with empty path returns the current outer entry', function (): void {
    $manifest = PipelineManifest::create(stepClasses: ['A', 'B']);
    $manifest->advanceStep();

    expect($manifest->stepClassAt([]))->toBe('B');
});

it('stepClassAt navigates a one-level nested path', function (): void {
    $manifest = PipelineManifest::create(stepClasses: [
        'A',
        ['type' => 'nested', 'name' => null, 'steps' => ['X', 'Y']],
    ]);

    expect($manifest->stepClassAt([1, 0]))->toBe('X')
        ->and($manifest->stepClassAt([1, 1]))->toBe('Y');
});

it('stepClassAt navigates two-level nested-inside-nested paths', function (): void {
    $manifest = PipelineManifest::create(stepClasses: [
        [
            'type' => 'nested',
            'name' => null,
            'steps' => [
                'A',
                ['type' => 'nested', 'name' => 'deep', 'steps' => ['B', 'C']],
            ],
        ],
    ]);

    expect($manifest->stepClassAt([0, 1, 0]))->toBe('B')
        ->and($manifest->stepClassAt([0, 1, 1]))->toBe('C');
});

it('stepClassAt navigates parallel-inside-nested to the parallel sub-step by index', function (): void {
    $manifest = PipelineManifest::create(stepClasses: [
        [
            'type' => 'nested',
            'name' => null,
            'steps' => [
                'A',
                ['type' => 'parallel', 'classes' => ['P1', 'P2']],
            ],
        ],
    ]);

    expect($manifest->stepClassAt([0, 1, 0]))->toBe('P1')
        ->and($manifest->stepClassAt([0, 1, 1]))->toBe('P2');
});

it('stepClassAt throws LogicException on out-of-bounds navigation', function (): void {
    $manifest = PipelineManifest::create(stepClasses: [
        ['type' => 'nested', 'name' => null, 'steps' => ['A']],
    ]);

    expect(fn () => $manifest->stepClassAt([0, 5]))->toThrow(LogicException::class);
});

it('withRekeyedStepConfig returns a deep-cloned manifest with the replaced stepConfigs entry', function (): void {
    $original = PipelineManifest::create(
        stepClasses: ['A', ['type' => 'nested', 'name' => null, 'steps' => ['B']]],
        stepConfigs: [
            0 => ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
            1 => ['type' => 'nested', 'configs' => [
                0 => ['queue' => 'inner-q', 'connection' => null, 'sync' => false, 'retry' => 3, 'backoff' => null, 'timeout' => null],
            ]],
        ],
    );

    $parallelShape = [
        'type' => 'parallel',
        'configs' => [
            0 => ['queue' => 'p-q', 'connection' => null, 'sync' => false, 'retry' => 2, 'backoff' => null, 'timeout' => null],
        ],
    ];

    $rekeyed = $original->withRekeyedStepConfig(1, $parallelShape);

    expect($rekeyed)->not->toBe($original)
        ->and($rekeyed->stepConfigs[1])->toBe($parallelShape)
        ->and($original->stepConfigs[1]['type'])->toBe('nested');
});

it('withRebrandedStepEntry returns a deep-cloned manifest with replaced stepClasses/stepConfigs/stepConditions entries', function (): void {
    $original = PipelineManifest::create(
        stepClasses: [
            'App\\Jobs\\A',
            [
                'type' => 'branch',
                'name' => null,
                'selector' => new SerializableClosure(fn ($ctx) => 'a'),
                'branches' => ['a' => 'App\\Jobs\\Left', 'b' => 'App\\Jobs\\Right'],
            ],
        ],
        stepConfigs: [
            0 => ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
            1 => [
                'type' => 'branch',
                'configs' => [
                    'a' => ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
                    'b' => ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
                ],
            ],
        ],
        stepConditions: [
            1 => [
                'type' => 'branch',
                'entries' => ['a' => null, 'b' => null],
            ],
        ],
    );

    $leftConfig = ['queue' => 'left-queue', 'connection' => null, 'sync' => false, 'retry' => 3, 'backoff' => null, 'timeout' => null];

    $rebranded = $original->withRebrandedStepEntry(1, 'App\\Jobs\\Left', $leftConfig, null);

    expect($rebranded)->not->toBe($original)
        ->and($rebranded->stepClasses[1])->toBe('App\\Jobs\\Left')
        ->and($rebranded->stepConfigs[1])->toBe($leftConfig)
        ->and($rebranded->stepConditions)->not->toHaveKey(1)
        ->and($original->stepClasses[1]['type'])->toBe('branch')
        ->and($original->stepConditions[1]['type'])->toBe('branch');
});

it('withRebrandedStepEntry preserves existing condition entry when a non-null replacement is passed', function (): void {
    $closure = new SerializableClosure(fn ($ctx) => true);
    $original = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A', 'App\\Jobs\\Old'],
        stepConfigs: [0 => [], 1 => []],
        stepConditions: [],
    );

    $newCondition = ['closure' => $closure, 'negated' => true];
    $rebranded = $original->withRebrandedStepEntry(1, 'App\\Jobs\\New', [], $newCondition);

    expect($rebranded->stepConditions[1])->toBeArray()
        ->and($rebranded->stepConditions[1])->toHaveKeys(['closure', 'negated'])
        ->and($rebranded->stepConditions[1]['negated'])->toBeTrue()
        ->and($rebranded->stepConditions[1]['closure'])->toBeInstanceOf(SerializableClosure::class)
        ->and($rebranded->stepClasses[1])->toBe('App\\Jobs\\New');
});

it('stepClassAt returns the branch shape verbatim when the cursor path lands on the branch outer position', function (): void {
    $branchShape = [
        'type' => 'branch',
        'name' => 'routing',
        'selector' => new SerializableClosure(fn ($ctx) => 'a'),
        'branches' => ['a' => 'App\\Jobs\\Left', 'b' => 'App\\Jobs\\Right'],
    ];

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A', $branchShape],
    );

    expect($manifest->stepClassAt([1]))->toBe($branchShape);
});

it('preserves dispatchEvents flag through withRekeyedStepConfig deep clone', function (): void {
    $original = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A'],
        stepConfigs: [0 => ['queue' => null]],
        dispatchEvents: true,
    );

    $rekeyed = $original->withRekeyedStepConfig(0, ['queue' => 'priority']);

    expect($rekeyed->dispatchEvents)->toBeTrue()
        ->and($rekeyed->stepConfigs[0])->toBe(['queue' => 'priority']);
});
