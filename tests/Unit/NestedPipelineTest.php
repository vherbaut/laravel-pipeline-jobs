<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\NestedPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;

it('wraps a pre-built PipelineDefinition via fromDefinition with a null name by default', function (): void {
    $inner = JobPipeline::make([FakeJobA::class, FakeJobB::class])->build();

    $nested = NestedPipeline::fromDefinition($inner);

    expect($nested)->toBeInstanceOf(NestedPipeline::class)
        ->and($nested->definition)->toBe($inner)
        ->and($nested->name)->toBeNull();
});

it('wraps a PipelineBuilder eagerly via fromBuilder and preserves the provided name', function (): void {
    $builder = JobPipeline::make([FakeJobA::class, FakeJobB::class]);

    $nested = NestedPipeline::fromBuilder($builder, 'checkout-pipeline');

    expect($nested->definition)->toBeInstanceOf(PipelineDefinition::class)
        ->and($nested->definition->stepCount())->toBe(2)
        ->and($nested->name)->toBe('checkout-pipeline');
});

it('propagates InvalidPipelineDefinition from fromBuilder when the builder has no steps', function (): void {
    expect(fn () => NestedPipeline::fromBuilder(JobPipeline::make()))
        ->toThrow(InvalidPipelineDefinition::class);
});

it('is a final class with readonly properties and a private constructor', function (): void {
    $reflection = new ReflectionClass(NestedPipeline::class);

    expect($reflection->isFinal())->toBeTrue();

    $definitionProperty = $reflection->getProperty('definition');
    expect($definitionProperty->isReadOnly())->toBeTrue();

    $nameProperty = $reflection->getProperty('name');
    expect($nameProperty->isReadOnly())->toBeTrue();

    expect($reflection->getConstructor()?->isPrivate())->toBeTrue();
});

it('snapshots the builder eagerly so subsequent mutations do not affect the wrapped definition', function (): void {
    $builder = JobPipeline::make([FakeJobA::class]);

    $nested = NestedPipeline::fromBuilder($builder);

    $builder->step(FakeJobB::class);

    expect($nested->definition->stepCount())->toBe(1);
});
