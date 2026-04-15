<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;

it('returns a StepDefinition carrying the condition and the jobClass from when()', function (): void {
    $condition = fn (SimpleContext $ctx): bool => $ctx->active;

    $step = Step::when($condition, FakeJobA::class);

    expect($step)->toBeInstanceOf(StepDefinition::class)
        ->and($step->jobClass)->toBe(FakeJobA::class)
        ->and($step->condition)->toBe($condition)
        ->and($step->conditionNegated)->toBeFalse();
});

it('returns a StepDefinition with conditionNegated=true from unless()', function (): void {
    $condition = fn (SimpleContext $ctx): bool => $ctx->active;

    $step = Step::unless($condition, FakeJobA::class);

    expect($step)->toBeInstanceOf(StepDefinition::class)
        ->and($step->jobClass)->toBe(FakeJobA::class)
        ->and($step->condition)->toBe($condition)
        ->and($step->conditionNegated)->toBeTrue();
});

it('returns a plain StepDefinition with no condition from make()', function (): void {
    $step = Step::make(FakeJobA::class);

    expect($step)->toBeInstanceOf(StepDefinition::class)
        ->and($step->jobClass)->toBe(FakeJobA::class)
        ->and($step->condition)->toBeNull()
        ->and($step->conditionNegated)->toBeFalse()
        ->and($step->compensationJobClass)->toBeNull();
});

it('Step::make composes with onQueue, onConnection, and sync fluent methods', function (): void {
    $step = Step::make(FakeJobA::class)
        ->onQueue('heavy')
        ->onConnection('redis')
        ->sync();

    // sync() drops queue and connection because dispatch_sync overrides both
    expect($step->jobClass)->toBe(FakeJobA::class)
        ->and($step->queue)->toBeNull()
        ->and($step->connection)->toBeNull()
        ->and($step->sync)->toBeTrue();
});

it('Step::when composes with onQueue without dropping the condition', function (): void {
    $condition = fn (SimpleContext $ctx): bool => $ctx->active;

    $step = Step::when($condition, FakeJobA::class)->onQueue('heavy');

    expect($step->queue)->toBe('heavy')
        ->and($step->condition)->toBe($condition)
        ->and($step->conditionNegated)->toBeFalse();
});

it('Step::unless composes with sync without dropping the negated flag', function (): void {
    $condition = fn (SimpleContext $ctx): bool => $ctx->active;

    $step = Step::unless($condition, FakeJobA::class)->sync();

    expect($step->sync)->toBeTrue()
        ->and($step->condition)->toBe($condition)
        ->and($step->conditionNegated)->toBeTrue();
});
