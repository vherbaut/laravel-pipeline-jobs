<?php

declare(strict_types=1);

use Laravel\SerializableClosure\SerializableClosure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('preserves a step condition closure through serialization round-trip', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        context: new SimpleContext,
        stepConditions: [
            0 => [
                'closure' => new SerializableClosure(fn (SimpleContext $ctx): bool => true),
                'negated' => false,
            ],
        ],
    );

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->stepConditions)->toHaveKey(0)
        ->and($restored->stepConditions[0]['negated'])->toBeFalse();

    $closure = $restored->stepConditions[0]['closure']->getClosure();

    expect($closure($restored->context))->toBeTrue();
});

it('preserves the negated flag through serialization round-trip', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        context: new SimpleContext,
        stepConditions: [
            0 => [
                'closure' => new SerializableClosure(fn () => true),
                'negated' => true,
            ],
        ],
    );

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->stepConditions[0]['negated'])->toBeTrue();
});

it('defaults stepConditions to an empty array when not provided', function (): void {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\StepOne']);

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    expect($restored->stepConditions)->toBe([]);
});

it('evaluates the restored closure against the restored context mutations', function (): void {
    $context = new SimpleContext;
    $context->active = true;

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        context: $context,
        stepConditions: [
            0 => [
                'closure' => new SerializableClosure(fn (SimpleContext $ctx): bool => $ctx->active),
                'negated' => false,
            ],
        ],
    );

    /** @var PipelineManifest $restored */
    $restored = unserialize(serialize($manifest));

    $closure = $restored->stepConditions[0]['closure']->getClosure();

    expect($closure($restored->context))->toBeTrue();
});
