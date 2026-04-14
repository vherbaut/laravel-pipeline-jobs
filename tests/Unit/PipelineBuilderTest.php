<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;

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

it('throws InvalidPipelineDefinition when compensateWith() is called twice on the same step', function () {
    $builder = (new PipelineBuilder)->step(FakeJobA::class)->compensateWith(FakeJobB::class);

    expect(fn () => $builder->compensateWith(FakeJobC::class))
        ->toThrow(InvalidPipelineDefinition::class, 'already defined');
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

// --- when() / unless() / addStep() / mixed array constructor ---

it('appends a conditional step via fluent when() with conditionNegated=false', function () {
    $condition = fn () => true;

    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->when($condition, FakeJobB::class)
        ->step(FakeJobC::class)
        ->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[1]->condition)->toBe($condition)
        ->and($definition->steps[1]->conditionNegated)->toBeFalse();
});

it('appends a conditional step via fluent unless() with conditionNegated=true', function () {
    $condition = fn () => true;

    $definition = (new PipelineBuilder)
        ->unless($condition, FakeJobA::class)
        ->build();

    expect($definition->steps[0]->condition)->toBe($condition)
        ->and($definition->steps[0]->conditionNegated)->toBeTrue();
});

it('returns the builder for fluent chaining from when() and unless()', function () {
    $builder = new PipelineBuilder;

    expect($builder->when(fn () => true, FakeJobA::class))->toBe($builder)
        ->and($builder->unless(fn () => true, FakeJobB::class))->toBe($builder);
});

it('appends a pre-built StepDefinition via addStep()', function () {
    $stepDefinition = Step::when(fn () => true, FakeJobA::class);

    $definition = (new PipelineBuilder)
        ->addStep($stepDefinition)
        ->build();

    expect($definition->steps)->toHaveCount(1)
        ->and($definition->steps[0])->toBe($stepDefinition);
});

it('accepts a mixed array of strings and StepDefinition instances in the constructor', function () {
    $condition = fn () => true;

    $builder = new PipelineBuilder([
        FakeJobA::class,
        Step::when($condition, FakeJobB::class),
        FakeJobC::class,
    ]);

    $definition = $builder->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($definition->steps[0]->condition)->toBeNull()
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[1]->condition)->toBe($condition)
        ->and($definition->steps[1]->conditionNegated)->toBeFalse()
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobC::class);
});

it('rejects non-string non-StepDefinition items in the constructor array', function () {
    expect(fn () => new PipelineBuilder([FakeJobA::class, 42, FakeJobC::class]))
        ->toThrow(InvalidPipelineDefinition::class, 'must be class-string or StepDefinition');
});

// --- return() ---

it('stores the return callback via return() and keeps fluent chain', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->return(fn () => 'x');

    expect($result)->toBeInstanceOf(PipelineBuilder::class)
        ->and($result)->toBe($builder);
});

it('overrides the return callback on multiple ->return() calls', function () {
    $builder = (new PipelineBuilder([FakeJobA::class]))
        ->return(fn () => 'first')
        ->return(fn () => 'second');

    $reflection = new ReflectionProperty(PipelineBuilder::class, 'returnCallback');
    $stored = $reflection->getValue($builder);

    expect($stored)->toBeInstanceOf(Closure::class)
        ->and($stored(new PipelineContext))->toBe('second');
});

it('accepts a closure whose parameter is typed as ?PipelineContext', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->return(fn (?PipelineContext $ctx) => $ctx?->name ?? 'fallback');

    expect($result)->toBe($builder);
});

// --- onFailure() ---

it('defaults build()->failStrategy to FailStrategy::StopImmediately when onFailure() is not called', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))->build();

    expect($definition->failStrategy)->toBe(FailStrategy::StopImmediately);
});

it('stores the strategy on the definition via onFailure()', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->onFailure(FailStrategy::StopAndCompensate)
        ->build();

    expect($definition->failStrategy)->toBe(FailStrategy::StopAndCompensate);
});

it('returns the same builder instance for fluent chaining from onFailure()', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->onFailure(FailStrategy::SkipAndContinue);

    expect($result)->toBe($builder);
});

it('applies last-write-wins when onFailure() is called multiple times', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->onFailure(FailStrategy::StopAndCompensate)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->build();

    expect($definition->failStrategy)->toBe(FailStrategy::SkipAndContinue);
});

it('rejects non-FailStrategy arguments via PHP native type check', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    /** @var mixed $badValue */
    $badValue = 'StopImmediately';

    expect(fn () => $builder->onFailure($badValue))->toThrow(TypeError::class);
});

// --- Story 6.1: Per-step lifecycle hooks ---

it('registers a beforeEach hook on the builder and propagates it to the built PipelineDefinition', function () {
    $hook = fn (StepDefinition $step, ?PipelineContext $ctx) => null;

    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->beforeEach($hook)
        ->build();

    expect($definition->beforeEachHooks)->toHaveCount(1)
        ->and($definition->beforeEachHooks[0])->toBe($hook);
});

it('registers an afterEach hook on the builder and propagates it to the built PipelineDefinition', function () {
    $hook = fn (StepDefinition $step, ?PipelineContext $ctx) => null;

    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->afterEach($hook)
        ->build();

    expect($definition->afterEachHooks)->toHaveCount(1)
        ->and($definition->afterEachHooks[0])->toBe($hook);
});

it('registers an onStepFailed hook on the builder and propagates it to the built PipelineDefinition', function () {
    $hook = fn (StepDefinition $step, ?PipelineContext $ctx, Throwable $e) => null;

    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->onStepFailed($hook)
        ->build();

    expect($definition->onStepFailedHooks)->toHaveCount(1)
        ->and($definition->onStepFailedHooks[0])->toBe($hook);
});

it('registers multiple hooks per kind in registration order (append-semantic)', function () {
    $before1 = fn (StepDefinition $s, ?PipelineContext $c) => null;
    $before2 = fn (StepDefinition $s, ?PipelineContext $c) => null;
    $after1 = fn (StepDefinition $s, ?PipelineContext $c) => null;
    $after2 = fn (StepDefinition $s, ?PipelineContext $c) => null;
    $failed1 = fn (StepDefinition $s, ?PipelineContext $c, Throwable $e) => null;
    $failed2 = fn (StepDefinition $s, ?PipelineContext $c, Throwable $e) => null;

    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->beforeEach($before1)
        ->beforeEach($before2)
        ->afterEach($after1)
        ->afterEach($after2)
        ->onStepFailed($failed1)
        ->onStepFailed($failed2)
        ->build();

    expect($definition->beforeEachHooks)->toBe([$before1, $before2])
        ->and($definition->afterEachHooks)->toBe([$after1, $after2])
        ->and($definition->onStepFailedHooks)->toBe([$failed1, $failed2]);
});

it('returns the same builder instance for fluent chaining from beforeEach()', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->beforeEach(fn (StepDefinition $s, ?PipelineContext $c) => null);

    expect($result)->toBe($builder);
});

it('returns the same builder instance for fluent chaining from afterEach()', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->afterEach(fn (StepDefinition $s, ?PipelineContext $c) => null);

    expect($result)->toBe($builder);
});

it('returns the same builder instance for fluent chaining from onStepFailed()', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->onStepFailed(fn (StepDefinition $s, ?PipelineContext $c, Throwable $e) => null);

    expect($result)->toBe($builder);
});

it('captures hooks eagerly in toListener() so later builder mutations do not bleed into the returned listener', function () {
    // AC #14: toListener() must resolve its hook set at call time; mutating
    // the builder after toListener() returns MUST NOT affect the listener.
    // TrackExecutionJobA is used here because it has a real handle() that
    // the listener can invoke synchronously (sync driver is the test default).
    TrackExecutionJob::$executionOrder = [];
    $calls = [];

    $builder = (new PipelineBuilder([TrackExecutionJobA::class]))
        ->send(new SimpleContext)
        ->beforeEach(function () use (&$calls): void {
            $calls[] = 'captured-at-toListener-time';
        });

    $listener = $builder->toListener();

    // Mutation AFTER toListener() — must be ignored by $listener.
    $builder->beforeEach(function () use (&$calls): void {
        $calls[] = 'registered-after-toListener';
    });

    $listener(new stdClass);

    expect($calls)->toBe(['captured-at-toListener-time']);
});

// --- Story 6.2: Pipeline-level callbacks ---

it('registers an onSuccess callback on the builder and passes it into the built PipelineDefinition', function () {
    $callback = fn (?PipelineContext $ctx) => null;

    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->onSuccess($callback)
        ->build();

    expect($definition->onSuccess)->toBe($callback);
});

it('registers an onComplete callback on the builder and passes it into the built PipelineDefinition', function () {
    $callback = fn (?PipelineContext $ctx) => null;

    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->onComplete($callback)
        ->build();

    expect($definition->onComplete)->toBe($callback);
});

it('registers an onFailure Closure callback alongside the FailStrategy setter', function () {
    $callback = fn (?PipelineContext $ctx, Throwable $e) => null;

    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->onFailure(FailStrategy::StopAndCompensate)
        ->onFailure($callback)
        ->build();

    expect($definition->failStrategy)->toBe(FailStrategy::StopAndCompensate)
        ->and($definition->onFailure)->toBe($callback);
});

it('applies last-write-wins for onSuccess, onFailure Closure, and onComplete', function () {
    $success1 = fn (?PipelineContext $ctx) => null;
    $success2 = fn (?PipelineContext $ctx) => null;
    $complete1 = fn (?PipelineContext $ctx) => null;
    $complete2 = fn (?PipelineContext $ctx) => null;
    $failure1 = fn (?PipelineContext $ctx, Throwable $e) => null;
    $failure2 = fn (?PipelineContext $ctx, Throwable $e) => null;

    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->onSuccess($success1)
        ->onSuccess($success2)
        ->onComplete($complete1)
        ->onComplete($complete2)
        ->onFailure($failure1)
        ->onFailure($failure2)
        ->build();

    expect($definition->onSuccess)->toBe($success2)
        ->and($definition->onComplete)->toBe($complete2)
        ->and($definition->onFailure)->toBe($failure2);
});

it('returns the same builder instance for fluent chaining from onSuccess() and onComplete()', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    expect($builder->onSuccess(fn (?PipelineContext $ctx) => null))->toBe($builder)
        ->and($builder->onComplete(fn (?PipelineContext $ctx) => null))->toBe($builder);
});

it('returns the same builder instance for fluent chaining from onFailure(Closure)', function () {
    $builder = new PipelineBuilder([FakeJobA::class]);

    $result = $builder->onFailure(fn (?PipelineContext $ctx, Throwable $e) => null);

    expect($result)->toBe($builder);
});

it('preserves the existing onFailure(FailStrategy) behavior unchanged after the union-type widening', function () {
    $builder = (new PipelineBuilder([FakeJobA::class]))
        ->onFailure(FailStrategy::SkipAndContinue);

    $definition = $builder->build();

    expect($definition->failStrategy)->toBe(FailStrategy::SkipAndContinue)
        ->and($definition->onFailure)->toBeNull();
});
