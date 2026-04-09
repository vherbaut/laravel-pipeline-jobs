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
