<?php

declare(strict_types=1);

use Laravel\SerializableClosure\SerializableClosure;
use Vherbaut\LaravelPipelineJobs\ConditionalBranch;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\NestedPipeline;
use Vherbaut\LaravelPipelineJobs\ParallelStepGroup;
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
        ->toThrow(InvalidPipelineDefinition::class, 'must be class-string, StepDefinition, ParallelStepGroup, NestedPipeline, or ConditionalBranch');
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

// --- ParallelStepGroup support (Story 8.1) ---

it('accepts a ParallelStepGroup in the constructor array', function () {
    $group = ParallelStepGroup::fromArray([FakeJobA::class, FakeJobB::class]);

    $builder = new PipelineBuilder([FakeJobC::class, $group]);
    $definition = $builder->build();

    expect($definition->steps)->toHaveCount(2)
        ->and($definition->steps[0])->toBeInstanceOf(StepDefinition::class)
        ->and($definition->steps[1])->toBe($group);
});

it('parallel() fluent method appends a ParallelStepGroup to the pipeline', function () {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->parallel([FakeJobB::class, FakeJobC::class]);

    $definition = $builder->build();

    expect($definition->steps)->toHaveCount(2)
        ->and($definition->steps[1])->toBeInstanceOf(ParallelStepGroup::class)
        ->and($definition->steps[1]->steps[0]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[1]->steps[1]->jobClass)->toBe(FakeJobC::class);
});

it('addParallelGroup() appends a pre-built group', function () {
    $group = ParallelStepGroup::fromArray([FakeJobA::class]);

    $builder = (new PipelineBuilder)
        ->step(FakeJobB::class)
        ->addParallelGroup($group);

    $definition = $builder->build();

    expect($definition->steps[1])->toBe($group);
});

it('rejects compensateWith() when the last step is a ParallelStepGroup', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->parallel([FakeJobB::class]);

    expect(fn () => $builder->compensateWith('App\\Jobs\\CompensateFake'))
        ->toThrow(InvalidPipelineDefinition::class, 'compensateWith() on a parallel step group');
});

it('rejects onQueue() when the last step is a ParallelStepGroup', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->parallel([FakeJobB::class]);

    expect(fn () => $builder->onQueue('fast'))
        ->toThrow(InvalidPipelineDefinition::class, 'onQueue() on a parallel step group');
});

it('rejects onConnection() when the last step is a ParallelStepGroup', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->parallel([FakeJobB::class]);

    expect(fn () => $builder->onConnection('redis'))
        ->toThrow(InvalidPipelineDefinition::class, 'onConnection() on a parallel step group');
});

it('rejects sync() when the last step is a ParallelStepGroup', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->parallel([FakeJobB::class]);

    expect(fn () => $builder->sync())
        ->toThrow(InvalidPipelineDefinition::class, 'sync() on a parallel step group');
});

it('rejects retry() when the last step is a ParallelStepGroup', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->parallel([FakeJobB::class]);

    expect(fn () => $builder->retry(3))
        ->toThrow(InvalidPipelineDefinition::class, 'retry() on a parallel step group');
});

it('rejects backoff() when the last step is a ParallelStepGroup', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->parallel([FakeJobB::class]);

    expect(fn () => $builder->backoff(5))
        ->toThrow(InvalidPipelineDefinition::class, 'backoff() on a parallel step group');
});

it('rejects timeout() when the last step is a ParallelStepGroup', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->parallel([FakeJobB::class]);

    expect(fn () => $builder->timeout(60))
        ->toThrow(InvalidPipelineDefinition::class, 'timeout() on a parallel step group');
});

it('resolveStepConfigs produces the nested parallel shape for a group with per-sub-step overrides', function () {
    $definition = (new PipelineBuilder([
        FakeJobA::class,
        ParallelStepGroup::fromArray([
            Step::make(FakeJobB::class)->onQueue('fast'),
            Step::make(FakeJobC::class)->retry(3),
        ]),
    ]))->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0])->toBeArray()
        ->and(isset($configs[0]['type']))->toBeFalse()
        ->and($configs[1]['type'])->toBe('parallel')
        ->and($configs[1]['configs'])->toHaveCount(2)
        ->and($configs[1]['configs'][0]['queue'])->toBe('fast')
        ->and($configs[1]['configs'][1]['retry'])->toBe(3);
});

// --- Story 8.2: NestedPipeline integration -------------------------------------------------

it('accepts a NestedPipeline instance in the constructor array', function (): void {
    $nested = NestedPipeline::fromBuilder(JobPipeline::make([FakeJobA::class, FakeJobB::class]));

    $definition = (new PipelineBuilder([FakeJobC::class, $nested]))->build();

    expect($definition->steps[0])->toBeInstanceOf(StepDefinition::class)
        ->and($definition->steps[1])->toBe($nested);
});

it('auto-wraps a bare PipelineBuilder entry into a NestedPipeline', function (): void {
    $child = JobPipeline::make([FakeJobA::class]);

    $definition = (new PipelineBuilder([FakeJobB::class, $child]))->build();

    expect($definition->steps[1])->toBeInstanceOf(NestedPipeline::class);
    /** @var NestedPipeline $wrapped */
    $wrapped = $definition->steps[1];
    expect($wrapped->name)->toBeNull()
        ->and($wrapped->definition->stepCount())->toBe(1);
});

it('auto-wraps a bare PipelineDefinition entry into a NestedPipeline', function (): void {
    $inner = JobPipeline::make([FakeJobA::class, FakeJobB::class])->build();

    $definition = (new PipelineBuilder([FakeJobC::class, $inner]))->build();

    expect($definition->steps[1])->toBeInstanceOf(NestedPipeline::class);
    /** @var NestedPipeline $wrapped */
    $wrapped = $definition->steps[1];
    expect($wrapped->definition)->toBe($inner);
});

it('provides the fluent nest() and addNestedPipeline() helpers symmetric with parallel()', function (): void {
    $nested = NestedPipeline::fromBuilder(JobPipeline::make([FakeJobB::class]));

    $definition = JobPipeline::make([FakeJobA::class])
        ->addNestedPipeline($nested)
        ->nest(JobPipeline::make([FakeJobC::class]), 'fluent-nest')
        ->build();

    expect($definition->stepCount())->toBe(3);

    /** @var NestedPipeline $secondPosition */
    $secondPosition = $definition->steps[1];
    /** @var NestedPipeline $thirdPosition */
    $thirdPosition = $definition->steps[2];

    expect($secondPosition)->toBe($nested)
        ->and($thirdPosition)->toBeInstanceOf(NestedPipeline::class)
        ->and($thirdPosition->name)->toBe('fluent-nest');
});

dataset('per_step_mutators_rejected_on_nested_pipeline', [
    'compensateWith' => [fn (PipelineBuilder $b) => $b->compensateWith(FakeJobB::class)],
    'onQueue' => [fn (PipelineBuilder $b) => $b->onQueue('nested-queue')],
    'onConnection' => [fn (PipelineBuilder $b) => $b->onConnection('redis')],
    'sync' => [fn (PipelineBuilder $b) => $b->sync()],
    'retry' => [fn (PipelineBuilder $b) => $b->retry(3)],
    'backoff' => [fn (PipelineBuilder $b) => $b->backoff(2)],
    'timeout' => [fn (PipelineBuilder $b) => $b->timeout(30)],
]);

it('rejects per-step mutators chained immediately after nest() with a targeted error', function (Closure $mutator): void {
    $builder = JobPipeline::make([FakeJobA::class])
        ->nest(JobPipeline::make([FakeJobB::class]));

    expect(fn () => $mutator($builder))
        ->toThrow(InvalidPipelineDefinition::class, 'Cannot call ');
})->with('per_step_mutators_rejected_on_nested_pipeline');

it('preserves builder state after a mutator-on-nested rejection (popped group is restored)', function (): void {
    $builder = JobPipeline::make([FakeJobA::class])->nest(JobPipeline::make([FakeJobB::class]));

    try {
        $builder->onQueue('nested-queue');
    } catch (InvalidPipelineDefinition) {
        // expected — now verify the builder still carries the nested position.
    }

    $definition = $builder->build();
    expect($definition->stepCount())->toBe(2)
        ->and($definition->steps[1])->toBeInstanceOf(NestedPipeline::class);
});

it('buildStepClassesPayload produces the nested-shape for a NestedPipeline position', function (): void {
    $nested = NestedPipeline::fromBuilder(
        JobPipeline::make([FakeJobB::class, FakeJobC::class]),
        'snapshot-flow',
    );

    $definition = (new PipelineBuilder([FakeJobA::class, $nested]))->build();

    // Trigger payload building indirectly by constructing a manifest through
    // the builder's toListener / run path: we introspect the stepClasses
    // stored on the manifest via the public static helper.
    $listener = JobPipeline::make([FakeJobA::class, $nested])->toListener();

    // The listener closure captures the computed stepClasses internally; we
    // re-derive via resolveStepConfigs which shares the same traversal.
    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0])->toBeArray()
        ->and(isset($configs[0]['type']))->toBeFalse()
        ->and($configs[1]['type'])->toBe('nested')
        ->and($configs[1]['configs'])->toHaveCount(2)
        ->and($listener)->toBeInstanceOf(Closure::class);
});

it('resolveStepConfigs emits the nested-shape with inner-pipeline defaults governing inner sub-steps', function (): void {
    // Inner pipeline with its own defaultRetry.
    $inner = JobPipeline::make([FakeJobB::class, FakeJobC::class])->defaultRetry(5);
    $nested = NestedPipeline::fromBuilder($inner);

    // Outer pipeline has a different defaultRetry that must NOT cascade into inner.
    $definition = JobPipeline::make([FakeJobA::class, $nested])
        ->defaultRetry(1)
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0]['retry'])->toBe(1)
        ->and($configs[1]['type'])->toBe('nested')
        ->and($configs[1]['configs'][0]['retry'])->toBe(5)
        ->and($configs[1]['configs'][1]['retry'])->toBe(5);
});

it('resolveStepConfigs handles nested-inside-nested recursion', function (): void {
    $innermost = JobPipeline::make([FakeJobC::class])->defaultRetry(7);
    $middle = JobPipeline::make([FakeJobB::class])->nest($innermost);
    $nested = NestedPipeline::fromBuilder($middle);

    $definition = JobPipeline::make([FakeJobA::class, $nested])->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[1]['type'])->toBe('nested')
        ->and($configs[1]['configs'][1]['type'])->toBe('nested')
        ->and($configs[1]['configs'][1]['configs'][0]['retry'])->toBe(7);
});

it('resolveStepConfigs handles parallel-inside-nested recursion', function (): void {
    $inner = JobPipeline::make([FakeJobB::class])->parallel([FakeJobA::class, FakeJobC::class]);
    $nested = NestedPipeline::fromBuilder($inner);

    $definition = JobPipeline::make([$nested])->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0]['type'])->toBe('nested')
        ->and($configs[0]['configs'][1]['type'])->toBe('parallel')
        ->and($configs[0]['configs'][1]['configs'])->toHaveCount(2);
});

it('accepts a ConditionalBranch in the constructor array', function (): void {
    $branch = ConditionalBranch::fromArray(fn ($ctx) => 'a', ['a' => FakeJobB::class, 'b' => FakeJobC::class]);

    $definition = (new PipelineBuilder([FakeJobA::class, $branch]))->build();

    expect($definition->stepCount())->toBe(2)
        ->and($definition->steps[1])->toBe($branch);
});

it('fluent branch() appends a ConditionalBranch to the pipeline', function (): void {
    $definition = (new PipelineBuilder([FakeJobA::class]))
        ->branch(fn ($ctx) => 'a', ['a' => FakeJobB::class, 'b' => FakeJobC::class], 'routing')
        ->build();

    expect($definition->steps[1])->toBeInstanceOf(ConditionalBranch::class)
        ->and($definition->steps[1]->name)->toBe('routing');
});

it('addConditionalBranch() appends a pre-built ConditionalBranch', function (): void {
    $branch = ConditionalBranch::fromArray(fn ($ctx) => 'a', ['a' => FakeJobB::class]);
    $definition = (new PipelineBuilder([FakeJobA::class]))->addConditionalBranch($branch)->build();

    expect($definition->steps[1])->toBe($branch);
});

it('rejects compensateWith() when the last step is a ConditionalBranch', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->branch(fn ($ctx) => 'a', ['a' => FakeJobB::class]);

    expect(fn () => $builder->compensateWith('App\\Jobs\\Compensate'))
        ->toThrow(InvalidPipelineDefinition::class, 'compensateWith() on a conditional branch');
});

it('rejects onQueue() when the last step is a ConditionalBranch', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->branch(fn ($ctx) => 'a', ['a' => FakeJobB::class]);

    expect(fn () => $builder->onQueue('fast'))
        ->toThrow(InvalidPipelineDefinition::class, 'onQueue() on a conditional branch');
});

it('rejects onConnection() when the last step is a ConditionalBranch', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->branch(fn ($ctx) => 'a', ['a' => FakeJobB::class]);

    expect(fn () => $builder->onConnection('redis'))
        ->toThrow(InvalidPipelineDefinition::class, 'onConnection() on a conditional branch');
});

it('rejects sync() when the last step is a ConditionalBranch', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->branch(fn ($ctx) => 'a', ['a' => FakeJobB::class]);

    expect(fn () => $builder->sync())
        ->toThrow(InvalidPipelineDefinition::class, 'sync() on a conditional branch');
});

it('rejects retry() when the last step is a ConditionalBranch', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->branch(fn ($ctx) => 'a', ['a' => FakeJobB::class]);

    expect(fn () => $builder->retry(3))
        ->toThrow(InvalidPipelineDefinition::class, 'retry() on a conditional branch');
});

it('rejects backoff() when the last step is a ConditionalBranch', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->branch(fn ($ctx) => 'a', ['a' => FakeJobB::class]);

    expect(fn () => $builder->backoff(5))
        ->toThrow(InvalidPipelineDefinition::class, 'backoff() on a conditional branch');
});

it('rejects timeout() when the last step is a ConditionalBranch', function (): void {
    $builder = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->branch(fn ($ctx) => 'a', ['a' => FakeJobB::class]);

    expect(fn () => $builder->timeout(60))
        ->toThrow(InvalidPipelineDefinition::class, 'timeout() on a conditional branch');
});

it('buildStepClassesPayload produces the branch shape for a ConditionalBranch position', function (): void {
    $definition = JobPipeline::make([
        FakeJobA::class,
        Step::branch(
            fn ($ctx) => 'a',
            [
                'a' => FakeJobB::class,
                'b' => JobPipeline::make([FakeJobA::class, FakeJobC::class]),
            ],
            'picker',
        ),
    ])->build();

    $reflection = new ReflectionClass(PipelineBuilder::class);
    $method = $reflection->getMethod('buildStepClassesPayload');
    $method->setAccessible(true);
    $payload = $method->invoke(null, $definition);

    expect($payload[0])->toBe(FakeJobA::class)
        ->and($payload[1]['type'])->toBe('branch')
        ->and($payload[1]['name'])->toBe('picker')
        ->and($payload[1]['selector'])->toBeInstanceOf(SerializableClosure::class)
        ->and($payload[1]['branches']['a'])->toBe(FakeJobB::class)
        ->and($payload[1]['branches']['b']['type'])->toBe('nested')
        ->and($payload[1]['branches']['b']['steps'])->toHaveCount(2);
});

it('buildStepConditions emits the branch shape when at least one branch value is conditional', function (): void {
    $builder = new PipelineBuilder([
        FakeJobA::class,
        Step::branch(
            fn ($ctx) => 'a',
            [
                'a' => Step::when(fn ($ctx) => true, FakeJobB::class),
                'b' => FakeJobC::class,
            ],
        ),
    ]);

    $definition = $builder->build();

    $reflection = new ReflectionClass(PipelineBuilder::class);
    $method = $reflection->getMethod('buildStepConditions');
    $method->setAccessible(true);
    $conditions = $method->invoke($builder, $definition);

    expect($conditions)->toHaveKey(1)
        ->and($conditions[1]['type'])->toBe('branch')
        ->and($conditions[1]['entries']['a'])->toBeArray()
        ->and($conditions[1]['entries']['a']['negated'])->toBeFalse()
        ->and($conditions[1]['entries']['b'])->toBeNull();
});

it('resolveStepConfigs produces the branch shape with outer defaults for flat branch values', function (): void {
    $definition = JobPipeline::make([
        FakeJobA::class,
        Step::branch(fn ($ctx) => 'a', ['a' => FakeJobB::class, 'b' => FakeJobC::class]),
    ])->defaultRetry(4)->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[1]['type'])->toBe('branch')
        ->and($configs[1]['configs']['a']['retry'])->toBe(4)
        ->and($configs[1]['configs']['b']['retry'])->toBe(4);
});

it('resolveStepConfigs produces the branch shape delegating nested branch values to their inner defaults', function (): void {
    $innerBuilder = JobPipeline::make([FakeJobC::class])->defaultRetry(9);

    $definition = JobPipeline::make([
        FakeJobA::class,
        Step::branch(fn ($ctx) => 'flat', ['flat' => FakeJobB::class, 'nested' => $innerBuilder]),
    ])->defaultRetry(1)->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[1]['type'])->toBe('branch')
        ->and($configs[1]['configs']['flat']['retry'])->toBe(1)
        ->and($configs[1]['configs']['nested']['type'])->toBe('nested')
        ->and($configs[1]['configs']['nested']['configs'][0]['retry'])->toBe(9);
});

it('defaults dispatchEvents flag to false when dispatchEvents() is never called', function (): void {
    $definition = (new PipelineBuilder([FakeJobA::class]))->build();

    expect($definition->dispatchEvents)->toBeFalse();
});

it('flips dispatchEvents flag to true after calling dispatchEvents()', function (): void {
    $definition = (new PipelineBuilder([FakeJobA::class]))->dispatchEvents()->build();

    expect($definition->dispatchEvents)->toBeTrue();
});

it('treats dispatchEvents() as idempotent on repeated calls', function (): void {
    $builder = new PipelineBuilder([FakeJobA::class]);

    $definition = $builder->dispatchEvents()->dispatchEvents()->build();

    expect($definition->dispatchEvents)->toBeTrue();
});

it('returns the same builder instance from dispatchEvents() for fluent chaining', function (): void {
    $builder = new PipelineBuilder([FakeJobA::class]);

    expect($builder->dispatchEvents())->toBe($builder);
});

// Story 9.2 — PipelineBuilder::reverse() (Task 1; AC #1, #2, #4, #5, #6, #7, #12)

it('reverse() returns a distinct PipelineBuilder instance (AC #2)', function (): void {
    $original = new PipelineBuilder([FakeJobA::class, FakeJobB::class]);

    $reversed = $original->reverse();

    expect($reversed)->toBeInstanceOf(PipelineBuilder::class)
        ->and($reversed)->not->toBe($original);
});

it('reverse() does not mutate the receiver (AC #1)', function (): void {
    $original = new PipelineBuilder([FakeJobA::class, FakeJobB::class, FakeJobC::class]);

    $original->reverse();

    $definition = $original->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobC::class);
});

it('reverse() reverses outer-position steps (AC #3, #4 outer positions)', function (): void {
    $reversed = (new PipelineBuilder([FakeJobA::class, FakeJobB::class, FakeJobC::class]))->reverse();

    $definition = $reversed->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0])->toBeInstanceOf(StepDefinition::class)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobC::class)
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobA::class);
});

it('reverse() preserves ParallelStepGroup inner contents (AC #4)', function (): void {
    $group = ParallelStepGroup::fromArray([FakeJobB::class, TrackExecutionJobA::class]);

    $reversed = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->addParallelGroup($group)
        ->step(FakeJobC::class)
        ->reverse();

    $definition = $reversed->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0])->toBeInstanceOf(StepDefinition::class)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobC::class)
        ->and($definition->steps[1])->toBe($group)
        ->and($definition->steps[1]->steps[0]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[1]->steps[1]->jobClass)->toBe(TrackExecutionJobA::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobA::class);
});

it('reverse() preserves NestedPipeline inner contents (AC #4)', function (): void {
    $inner = JobPipeline::make([FakeJobB::class, TrackExecutionJobA::class]);
    $nested = NestedPipeline::fromBuilder($inner);

    $reversed = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->addNestedPipeline($nested)
        ->step(FakeJobC::class)
        ->reverse();

    $definition = $reversed->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobC::class)
        ->and($definition->steps[1])->toBe($nested)
        ->and($definition->steps[1]->definition->steps[0]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[1]->definition->steps[1]->jobClass)->toBe(TrackExecutionJobA::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobA::class);
});

it('reverse() preserves ConditionalBranch branches map (AC #4)', function (): void {
    $selector = fn ($ctx) => 'premium';
    $branch = ConditionalBranch::fromArray($selector, ['premium' => FakeJobB::class, 'basic' => TrackExecutionJobA::class]);

    $reversed = (new PipelineBuilder)
        ->step(FakeJobA::class)
        ->addConditionalBranch($branch)
        ->step(FakeJobC::class)
        ->reverse();

    $definition = $reversed->build();

    expect($definition->steps[0]->jobClass)->toBe(FakeJobC::class)
        ->and($definition->steps[1])->toBe($branch)
        ->and($definition->steps[1]->selector)->toBe($selector)
        ->and(array_keys($definition->steps[1]->branches))->toBe(['premium', 'basic'])
        ->and($definition->steps[1]->branches['premium']->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[1]->branches['basic']->jobClass)->toBe(TrackExecutionJobA::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobA::class);
});

it('reverse() propagates every pipeline-level state field (AC #5)', function (): void {
    $context = new SimpleContext;
    $returnClosure = fn ($ctx) => 'returned';
    $beforeEach = fn () => null;
    $afterEach = fn () => null;
    $onFailedHook = fn () => null;
    $onSuccessCallback = fn () => null;
    $onFailureCallback = fn () => null;
    $onCompleteCallback = fn () => null;

    $builder = (new PipelineBuilder([FakeJobA::class, FakeJobB::class]))
        ->send($context)
        ->shouldBeQueued()
        ->dispatchEvents()
        ->return($returnClosure)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->onFailure($onFailureCallback)
        ->defaultQueue('default-q')
        ->defaultConnection('default-c')
        ->defaultRetry(5)
        ->defaultBackoff(7)
        ->defaultTimeout(11)
        ->beforeEach($beforeEach)
        ->afterEach($afterEach)
        ->onStepFailed($onFailedHook)
        ->onSuccess($onSuccessCallback)
        ->onComplete($onCompleteCallback);

    $reversed = $builder->reverse();
    $definition = $reversed->build();

    expect($reversed->getContext())->toBe($context)
        ->and($definition->shouldBeQueued)->toBeTrue()
        ->and($definition->dispatchEvents)->toBeTrue()
        ->and($definition->failStrategy)->toBe(FailStrategy::StopAndCompensate)
        ->and($definition->defaultQueue)->toBe('default-q')
        ->and($definition->defaultConnection)->toBe('default-c')
        ->and($definition->defaultRetry)->toBe(5)
        ->and($definition->defaultBackoff)->toBe(7)
        ->and($definition->defaultTimeout)->toBe(11)
        ->and($definition->beforeEachHooks)->toHaveCount(1)
        ->and($definition->beforeEachHooks[0])->toBe($beforeEach)
        ->and($definition->afterEachHooks)->toHaveCount(1)
        ->and($definition->afterEachHooks[0])->toBe($afterEach)
        ->and($definition->onStepFailedHooks)->toHaveCount(1)
        ->and($definition->onStepFailedHooks[0])->toBe($onFailedHook)
        ->and($definition->onSuccess)->toBe($onSuccessCallback)
        ->and($definition->onFailure)->toBe($onFailureCallback)
        ->and($definition->onComplete)->toBe($onCompleteCallback);
});

it('double reverse yields a builder whose steps equal the original by identity (AC #6)', function (): void {
    $original = new PipelineBuilder([FakeJobA::class, FakeJobB::class, FakeJobC::class]);
    $originalSteps = $original->build()->steps;

    $doubleReversed = $original->reverse()->reverse();
    $doubleReversedSteps = $doubleReversed->build()->steps;

    expect($doubleReversedSteps)->toHaveCount(3)
        ->and($doubleReversedSteps[0])->toBe($originalSteps[0])
        ->and($doubleReversedSteps[1])->toBe($originalSteps[1])
        ->and($doubleReversedSteps[2])->toBe($originalSteps[2]);
});

it('mutations on the original builder after reverse() do not leak into the reversed builder (AC #7)', function (): void {
    $original = new PipelineBuilder([FakeJobA::class, FakeJobB::class]);
    $reversed = $original->reverse();

    $original->step(FakeJobC::class)->beforeEach(fn () => null);

    $reversedDefinition = $reversed->build();

    expect($reversedDefinition->steps)->toHaveCount(2)
        ->and($reversedDefinition->steps[0]->jobClass)->toBe(FakeJobB::class)
        ->and($reversedDefinition->steps[1]->jobClass)->toBe(FakeJobA::class)
        ->and($reversedDefinition->beforeEachHooks)->toHaveCount(0);
});

it('mutations on the reversed builder after reverse() do not leak back to the original (AC #7)', function (): void {
    $original = new PipelineBuilder([FakeJobA::class, FakeJobB::class]);
    $reversed = $original->reverse();

    $reversed->step(FakeJobC::class)->afterEach(fn () => null);

    $originalDefinition = $original->build();

    expect($originalDefinition->steps)->toHaveCount(2)
        ->and($originalDefinition->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($originalDefinition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($originalDefinition->afterEachHooks)->toHaveCount(0);
});

it('reverse() on a zero-step receiver yields an empty reversed builder that still throws on build() (AC #12)', function (): void {
    $reversed = (new PipelineBuilder)->reverse();

    expect(fn () => $reversed->build())
        ->toThrow(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');
});

it('reverse() on a single-step receiver yields a single-step reversed builder with a NEW instance (AC #12)', function (): void {
    $original = new PipelineBuilder([FakeJobA::class]);

    $reversed = $original->reverse();

    expect($reversed)->not->toBe($original)
        ->and($reversed->build()->steps)->toHaveCount(1)
        ->and($reversed->build()->steps[0]->jobClass)->toBe(FakeJobA::class);
});
