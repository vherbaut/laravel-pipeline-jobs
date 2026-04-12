<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;

it('creates a builder with correct step count from job class array', function () {
    $builder = new PipelineBuilder([FakeJobA::class, FakeJobB::class, FakeJobC::class]);

    $definition = $builder->build();

    expect($definition)->toBeInstanceOf(PipelineDefinition::class)
        ->and($definition->stepCount())->toBe(3);
});

it('creates a builder with zero steps from empty array', function () {
    $builder = new PipelineBuilder([]);

    expect(fn () => $builder->build())
        ->toThrow(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');
});

it('stores a PipelineContext instance via send()', function () {
    $context = new PipelineContext;
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->send($context);

    expect($result)->toBeInstanceOf(PipelineBuilder::class)
        ->and($result->getContext())->toBe($context);
});

it('stores a Closure via send() for deferred resolution', function () {
    $closure = fn () => new PipelineContext;
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->send($closure);

    expect($result)->toBeInstanceOf(PipelineBuilder::class)
        ->and($result->getContext())->toBe($closure);
});

it('produces a PipelineDefinition with correct steps in order via build()', function () {
    $builder = new PipelineBuilder([FakeJobA::class, FakeJobB::class, FakeJobC::class]);

    $definition = $builder->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0])->toBeInstanceOf(StepDefinition::class)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobC::class);
});

it('throws InvalidPipelineDefinition when building with zero steps', function () {
    $builder = new PipelineBuilder;

    expect(fn () => $builder->build())
        ->toThrow(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');
});

it('includes stored context in the builder after build()', function () {
    $context = new PipelineContext;
    $builder = new PipelineBuilder([FakeJobA::class]);

    $builder->send($context);
    $builder->build();

    expect($builder->getContext())->toBe($context);
});

it('supports method chaining: make then send then build', function () {
    $context = new PipelineContext;

    $definition = (new PipelineBuilder([FakeJobA::class, FakeJobB::class]))
        ->send($context)
        ->build();

    expect($definition)->toBeInstanceOf(PipelineDefinition::class)
        ->and($definition->stepCount())->toBe(2);
});

it('adds a step via step() and returns the builder for fluent chaining', function () {
    $builder = new PipelineBuilder;

    $result = $builder->step(FakeJobA::class);

    expect($result)->toBe($builder)
        ->and($builder->build()->stepCount())->toBe(1)
        ->and($builder->build()->steps[0]->jobClass)->toBe(FakeJobA::class);
});

it('accumulates multiple step() calls in order', function () {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->step(FakeJobB::class)
        ->step(FakeJobC::class);

    $definition = $builder->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobC::class);
});

it('preserves order when mixing array constructor and step() calls', function () {
    $builder = (new PipelineBuilder([FakeJobA::class, FakeJobB::class]))
        ->step(FakeJobC::class);

    $definition = $builder->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobC::class);
});

it('works with step() after empty constructor', function () {
    $builder = (new PipelineBuilder)->step(FakeJobA::class);

    $definition = $builder->build();

    expect($definition->stepCount())->toBe(1)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class);
});

it('produces identical PipelineDefinition from fluent and array APIs', function () {
    $arrayDefinition = (new PipelineBuilder([FakeJobA::class, FakeJobB::class, FakeJobC::class]))->build();
    $fluentDefinition = (new PipelineBuilder)->step(FakeJobA::class)->step(FakeJobB::class)->step(FakeJobC::class)->build();

    expect($fluentDefinition->stepCount())->toBe($arrayDefinition->stepCount());

    foreach ($arrayDefinition->steps as $index => $step) {
        expect($fluentDefinition->steps[$index])->toEqual($step);
    }
});

it('returns the builder for fluent chaining from shouldBeQueued()', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->shouldBeQueued();

    expect($result)->toBe($builder);
});

it('defaults shouldBeQueued to false on a fresh builder', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))->build();

    expect($definition->shouldBeQueued)->toBeFalse();
});

it('propagates shouldBeQueued() to the built PipelineDefinition', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->shouldBeQueued()
        ->build();

    expect($definition->shouldBeQueued)->toBeTrue();
});

it('treats multiple shouldBeQueued() calls as idempotent', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->shouldBeQueued()
        ->shouldBeQueued()
        ->shouldBeQueued()
        ->build();

    expect($definition->shouldBeQueued)->toBeTrue();
});

it('returns a Closure from toListener()', function () {
    $builder = (new PipelineBuilder([FakeJobA::class]))
        ->send(new PipelineContext);

    expect($builder->toListener())->toBeInstanceOf(Closure::class);
});

it('throws InvalidPipelineDefinition when toListener() is called on an empty builder', function () {
    $builder = new PipelineBuilder;

    expect(fn () => $builder->toListener())
        ->toThrow(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');
});

it('returns distinct Closure instances on repeated toListener() calls', function () {
    $builder = (new PipelineBuilder([FakeJobA::class]))
        ->send(new PipelineContext);

    $first = $builder->toListener();
    $second = $builder->toListener();

    expect($first)->not->toBe($second);
});

// --- compensateWith() ---

it('sets compensationJobClass on the last step via compensateWith()', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->compensateWith(FakeJobB::class)
        ->build();

    expect($definition->steps[0]->compensationJobClass)->toBe(FakeJobB::class);
});

it('returns the builder for fluent chaining from compensateWith()', function () {
    $builder = (new PipelineBuilder)->step(FakeJobA::class);

    $result = $builder->compensateWith(FakeJobB::class);

    expect($result)->toBe($builder);
});

it('throws InvalidPipelineDefinition when compensateWith() is called with no steps', function () {
    $builder = new PipelineBuilder;

    expect(fn () => $builder->compensateWith(FakeJobA::class))
        ->toThrow(InvalidPipelineDefinition::class);
});

it('only sets compensation on the last step, not earlier ones', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->step(FakeJobB::class)
        ->compensateWith(FakeJobC::class)
        ->build();

    expect($definition->steps[0]->compensationJobClass)->toBeNull()
        ->and($definition->steps[1]->compensationJobClass)->toBe(FakeJobC::class);
});

it('preserves all original StepDefinition properties when compensateWith() rebuilds the step', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->compensateWith(FakeJobB::class)
        ->build();

    $step = $definition->steps[0];

    expect($step->jobClass)->toBe(FakeJobA::class)
        ->and($step->compensationJobClass)->toBe(FakeJobB::class)
        ->and($step->condition)->toBeNull()
        ->and($step->conditionNegated)->toBeFalse()
        ->and($step->queue)->toBeNull()
        ->and($step->connection)->toBeNull()
        ->and($step->retry)->toBeNull()
        ->and($step->backoff)->toBeNull()
        ->and($step->timeout)->toBeNull()
        ->and($step->sync)->toBeFalse();
});
