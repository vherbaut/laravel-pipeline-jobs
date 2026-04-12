<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\OrderContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('can be instantiated as a subclass with typed properties', function () {
    $context = new SimpleContext;
    $context->name = 'pipeline-a';
    $context->count = 10;
    $context->active = true;
    $context->tags = ['alpha'];

    expect($context)
        ->toBeInstanceOf(PipelineContext::class)
        ->toBeInstanceOf(SimpleContext::class)
        ->and($context->name)->toBe('pipeline-a')
        ->and($context->count)->toBe(10)
        ->and($context->active)->toBeTrue()
        ->and($context->tags)->toBe(['alpha']);
});

it('preserves scalar properties through serialization round-trip', function () {
    $context = new SimpleContext;
    $context->name = 'test-pipeline';
    $context->count = 42;
    $context->active = true;
    $context->tags = ['foo', 'bar'];

    /** @var SimpleContext $restored */
    $restored = unserialize(serialize($context));

    expect($restored->name)->toBe('test-pipeline')
        ->and($restored->count)->toBe(42)
        ->and($restored->active)->toBeTrue()
        ->and($restored->tags)->toBe(['foo', 'bar']);
});

it('preserves null values through serialization round-trip', function () {
    $context = new OrderContext;
    $context->orderId = 'ORD-001';
    $context->user = null;

    /** @var OrderContext $restored */
    $restored = unserialize(serialize($context));

    expect($restored->orderId)->toBe('ORD-001')
        ->and($restored->user)->toBeNull();
});

it('preserves nested arrays through serialization round-trip', function () {
    $context = new SimpleContext;
    $context->tags = [
        'level1' => [
            'level2' => [
                'key' => 'deep-value',
                'numbers' => [1, 2, 3],
            ],
        ],
        'flat' => 'simple',
    ];

    /** @var SimpleContext $restored */
    $restored = unserialize(serialize($context));

    expect($restored->tags)->toBe([
        'level1' => [
            'level2' => [
                'key' => 'deep-value',
                'numbers' => [1, 2, 3],
            ],
        ],
        'flat' => 'simple',
    ]);
});
