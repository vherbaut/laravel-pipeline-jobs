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

it('onQueue rebuilds the last step with the queue override', function () {
    $builder = (new PipelineBuilder([FakeJobA::class]))
        ->onQueue('heavy');

    $definition = $builder->build();

    expect($definition->steps[0]->queue)->toBe('heavy')
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class);
});

it('onConnection rebuilds the last step with the connection override', function () {
    $builder = (new PipelineBuilder([FakeJobA::class]))
        ->onConnection('redis');

    $definition = $builder->build();

    expect($definition->steps[0]->connection)->toBe('redis');
});

it('sync rebuilds the last step with sync flag set to true', function () {
    $builder = (new PipelineBuilder([FakeJobA::class]))
        ->sync();

    $definition = $builder->build();

    expect($definition->steps[0]->sync)->toBeTrue();
});

it('onQueue throws InvalidPipelineDefinition when called before any step is added', function () {
    $builder = new PipelineBuilder;

    expect(fn () => $builder->onQueue('heavy'))
        ->toThrow(InvalidPipelineDefinition::class, 'before adding a step');
});

it('onConnection throws InvalidPipelineDefinition when called before any step is added', function () {
    $builder = new PipelineBuilder;

    expect(fn () => $builder->onConnection('redis'))
        ->toThrow(InvalidPipelineDefinition::class, 'before adding a step');
});

it('sync throws InvalidPipelineDefinition when called before any step is added', function () {
    $builder = new PipelineBuilder;

    expect(fn () => $builder->sync())
        ->toThrow(InvalidPipelineDefinition::class, 'before adding a step');
});

it('onQueue applies last-write-wins when called twice on the same step', function () {
    $builder = (new PipelineBuilder([FakeJobA::class]))
        ->onQueue('first')
        ->onQueue('second');

    $definition = $builder->build();

    expect($definition->steps[0]->queue)->toBe('second');
});

it('onQueue only rebuilds the last step when multiple steps exist', function () {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->step(FakeJobB::class)
        ->onQueue('heavy')
        ->step(FakeJobC::class);

    $definition = $builder->build();

    expect($definition->steps[0]->queue)->toBeNull()
        ->and($definition->steps[1]->queue)->toBe('heavy')
        ->and($definition->steps[2]->queue)->toBeNull();
});

it('defaultQueue stores the pipeline-level default queue', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->defaultQueue('background')
        ->build();

    expect($definition->defaultQueue)->toBe('background');
});

it('defaultConnection stores the pipeline-level default connection', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->defaultConnection('redis')
        ->build();

    expect($definition->defaultConnection)->toBe('redis');
});

it('defaultQueue can be called before any step has been added', function () {
    $definition = (new PipelineBuilder)
        ->defaultQueue('background')
        ->step(FakeJobA::class)
        ->build();

    expect($definition->defaultQueue)->toBe('background');
});

it('defaultConnection can be called before any step has been added', function () {
    $definition = (new PipelineBuilder)
        ->defaultConnection('redis')
        ->step(FakeJobA::class)
        ->build();

    expect($definition->defaultConnection)->toBe('redis');
});

it('defaultQueue applies last-write-wins', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->defaultQueue('first')
        ->defaultQueue('second')
        ->build();

    expect($definition->defaultQueue)->toBe('second');
});

it('resolveStepConfigs resolves step override over pipeline default', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)->onQueue('heavy')
        ->step(FakeJobB::class)
        ->defaultQueue('background')
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0])->toBe(['queue' => 'heavy', 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null])
        ->and($configs[1])->toBe(['queue' => 'background', 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null]);
});

it('resolveStepConfigs falls through to null when neither step nor pipeline default is set', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0])->toBe(['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null]);
});

it('resolveStepConfigs resolves defaultConnection for steps without explicit override', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)->onConnection('beanstalkd')
        ->step(FakeJobB::class)
        ->defaultConnection('redis')
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0]['connection'])->toBe('beanstalkd')
        ->and($configs[1]['connection'])->toBe('redis');
});

it('resolveStepConfigs carries sync flag from step override without pipeline default', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)->sync()
        ->step(FakeJobB::class)
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0]['sync'])->toBeTrue()
        ->and($configs[1]['sync'])->toBeFalse();
});

it('resolveStepConfigs produces one entry per step (count invariant with stepClasses)', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->step(FakeJobB::class)->onQueue('heavy')
        ->step(FakeJobC::class)->sync()
        ->defaultQueue('background')
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs)->toHaveCount(count($definition->steps))
        ->and(array_keys($configs))->toBe([0, 1, 2]);
});

it('threads Step::make()->onQueue() through the array API into the resolved step', function () {
    $builder = new PipelineBuilder([
        Step::make(FakeJobA::class)->onQueue('heavy'),
    ]);

    $definition = $builder->build();

    expect($definition->steps[0]->queue)->toBe('heavy')
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class);
});

it('retry registers on the last step and propagates to the resolved manifest', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)->retry(3)
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($definition->steps[0]->retry)->toBe(3)
        ->and($configs[0]['retry'])->toBe(3);
});

it('backoff registers on the last step and propagates to the resolved manifest', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)->retry(3)->backoff(5)
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($definition->steps[0]->backoff)->toBe(5)
        ->and($configs[0]['backoff'])->toBe(5);
});

it('timeout registers on the last step and propagates to the resolved manifest', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)->timeout(60)
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($definition->steps[0]->timeout)->toBe(60)
        ->and($configs[0]['timeout'])->toBe(60);
});

it('defaultRetry registers on the definition', function () {
    $definition = (new PipelineBuilder)
        ->defaultRetry(2)
        ->step(FakeJobA::class)
        ->build();

    expect($definition->defaultRetry)->toBe(2);
});

it('defaultBackoff registers on the definition', function () {
    $definition = (new PipelineBuilder)
        ->defaultBackoff(3)
        ->step(FakeJobA::class)
        ->build();

    expect($definition->defaultBackoff)->toBe(3);
});

it('defaultTimeout registers on the definition', function () {
    $definition = (new PipelineBuilder)
        ->defaultTimeout(45)
        ->step(FakeJobA::class)
        ->build();

    expect($definition->defaultTimeout)->toBe(45);
});

it('retry throws InvalidPipelineDefinition when called before any step', function () {
    (new PipelineBuilder)->retry(3);
})->throws(InvalidPipelineDefinition::class, 'before adding a step');

it('backoff throws InvalidPipelineDefinition when called before any step', function () {
    (new PipelineBuilder)->backoff(5);
})->throws(InvalidPipelineDefinition::class, 'before adding a step');

it('timeout throws InvalidPipelineDefinition when called before any step', function () {
    (new PipelineBuilder)->timeout(60);
})->throws(InvalidPipelineDefinition::class, 'before adding a step');

it('retry rejects negative values', function () {
    (new PipelineBuilder)->step(FakeJobA::class)->retry(-1);
})->throws(InvalidPipelineDefinition::class, 'retry must be a non-negative integer, got -1');

it('backoff rejects negative values', function () {
    (new PipelineBuilder)->step(FakeJobA::class)->backoff(-1);
})->throws(InvalidPipelineDefinition::class, 'backoff must be a non-negative integer, got -1');

it('timeout rejects zero', function () {
    (new PipelineBuilder)->step(FakeJobA::class)->timeout(0);
})->throws(InvalidPipelineDefinition::class, 'timeout must be a positive integer (>= 1), got 0');

it('timeout rejects negative values', function () {
    (new PipelineBuilder)->step(FakeJobA::class)->timeout(-5);
})->throws(InvalidPipelineDefinition::class, 'timeout must be a positive integer (>= 1), got -5');

it('defaultRetry rejects negative values', function () {
    (new PipelineBuilder)->defaultRetry(-1);
})->throws(InvalidPipelineDefinition::class, 'retry must be a non-negative integer, got -1');

it('defaultBackoff rejects negative values', function () {
    (new PipelineBuilder)->defaultBackoff(-1);
})->throws(InvalidPipelineDefinition::class, 'backoff must be a non-negative integer, got -1');

it('defaultTimeout rejects zero', function () {
    (new PipelineBuilder)->defaultTimeout(0);
})->throws(InvalidPipelineDefinition::class, 'timeout must be a positive integer (>= 1), got 0');

it('defaultRetry/Backoff/Timeout can be called before any step', function () {
    $definition = (new PipelineBuilder)
        ->defaultRetry(2)
        ->defaultBackoff(3)
        ->defaultTimeout(60)
        ->step(FakeJobA::class)
        ->build();

    expect($definition->defaultRetry)->toBe(2)
        ->and($definition->defaultBackoff)->toBe(3)
        ->and($definition->defaultTimeout)->toBe(60);
});

it('retry/backoff/timeout apply last-write-wins on the same step', function () {
    $definition = (new PipelineBuilder)
        ->step(FakeJobA::class)->retry(1)->retry(5)
        ->backoff(1)->backoff(10)
        ->timeout(30)->timeout(120)
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0]['retry'])->toBe(5)
        ->and($configs[0]['backoff'])->toBe(10)
        ->and($configs[0]['timeout'])->toBe(120);
});

it('step override takes precedence over pipeline defaults for retry/backoff/timeout', function () {
    $definition = (new PipelineBuilder)
        ->defaultRetry(1)
        ->defaultBackoff(2)
        ->defaultTimeout(30)
        ->step(FakeJobA::class)->retry(5)->backoff(7)->timeout(60)
        ->step(FakeJobB::class)
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0])->toBe(['queue' => null, 'connection' => null, 'sync' => false, 'retry' => 5, 'backoff' => 7, 'timeout' => 60])
        ->and($configs[1])->toBe(['queue' => null, 'connection' => null, 'sync' => false, 'retry' => 1, 'backoff' => 2, 'timeout' => 30]);
});

it('resolveStepConfigs produces the extended six-key shape', function () {
    $definition = (new PipelineBuilder([FakeJobA::class]))->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect(array_keys($configs[0]))->toBe(['queue', 'connection', 'sync', 'retry', 'backoff', 'timeout']);
});

it('threads Step::make()->retry()->timeout() through the array API into the resolved step', function () {
    $builder = new PipelineBuilder([
        Step::make(FakeJobA::class)->retry(3)->backoff(5)->timeout(60),
    ]);

    $definition = $builder->build();

    expect($definition->steps[0]->retry)->toBe(3)
        ->and($definition->steps[0]->backoff)->toBe(5)
        ->and($definition->steps[0]->timeout)->toBe(60)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class);
});
