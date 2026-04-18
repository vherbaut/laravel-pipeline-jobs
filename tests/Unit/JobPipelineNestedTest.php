<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\NestedPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;

it('JobPipeline::nest() wraps a PipelineBuilder argument via fromBuilder', function (): void {
    $builder = JobPipeline::make([FakeJobA::class, FakeJobB::class]);

    $nested = JobPipeline::nest($builder);

    expect($nested)->toBeInstanceOf(NestedPipeline::class)
        ->and($nested->definition->stepCount())->toBe(2)
        ->and($nested->name)->toBeNull();
});

it('JobPipeline::nest() wraps a PipelineDefinition argument via fromDefinition and preserves the explicit name', function (): void {
    $definition = JobPipeline::make([FakeJobA::class])->build();

    $nested = JobPipeline::nest($definition, 'child-flow');

    expect($nested->definition)->toBe($definition)
        ->and($nested->name)->toBe('child-flow');
});

it('JobPipeline::nest() propagates InvalidPipelineDefinition when called with an empty builder', function (): void {
    expect(fn () => JobPipeline::nest(JobPipeline::make()))
        ->toThrow(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');
});

it('JobPipeline::nest() placement is between parallel() and listen() to preserve method ordering', function (): void {
    $methods = (new ReflectionClass(JobPipeline::class))->getMethods(ReflectionMethod::IS_STATIC);
    $names = array_values(array_map(static fn (ReflectionMethod $m): string => $m->getName(), $methods));

    $parallelIndex = array_search('parallel', $names, true);
    $nestIndex = array_search('nest', $names, true);
    $listenIndex = array_search('listen', $names, true);

    expect($parallelIndex)->toBeLessThan($nestIndex)
        ->and($nestIndex)->toBeLessThan($listenIndex);
});
