<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\ConditionalBranch;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\NestedPipeline;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;

it('normalizes class-string branch values into StepDefinition instances', function (): void {
    $branch = ConditionalBranch::fromArray(
        fn ($ctx) => 'left',
        [
            'left' => FakeJobA::class,
            'right' => FakeJobB::class,
        ],
        'routing',
    );

    expect($branch->branches)->toHaveKeys(['left', 'right'])
        ->and($branch->branches['left'])->toBeInstanceOf(StepDefinition::class)
        ->and($branch->branches['left']->jobClass)->toBe(FakeJobA::class)
        ->and($branch->branches['right'])->toBeInstanceOf(StepDefinition::class)
        ->and($branch->branches['right']->jobClass)->toBe(FakeJobB::class)
        ->and($branch->name)->toBe('routing');
});

it('accepts mixed StepDefinition and NestedPipeline branch values as-is', function (): void {
    $stepDef = StepDefinition::fromJobClass(FakeJobA::class);
    $nested = NestedPipeline::fromBuilder(JobPipeline::make([FakeJobB::class, FakeJobC::class]), 'premium');

    $branch = ConditionalBranch::fromArray(
        fn ($ctx) => 'standard',
        [
            'standard' => $stepDef,
            'premium' => $nested,
        ],
    );

    expect($branch->branches['standard'])->toBe($stepDef)
        ->and($branch->branches['premium'])->toBe($nested)
        ->and($branch->name)->toBeNull();
});

it('auto-wraps a PipelineBuilder branch value into a NestedPipeline', function (): void {
    $builder = JobPipeline::make([FakeJobA::class, FakeJobB::class]);

    $branch = ConditionalBranch::fromArray(
        fn ($ctx) => 'only',
        ['only' => $builder],
    );

    expect($branch->branches['only'])->toBeInstanceOf(NestedPipeline::class)
        ->and($branch->branches['only']->definition->stepCount())->toBe(2);
});

it('auto-wraps a PipelineDefinition branch value into a NestedPipeline', function (): void {
    $definition = JobPipeline::make([FakeJobA::class])->build();

    $branch = ConditionalBranch::fromArray(
        fn ($ctx) => 'only',
        ['only' => $definition],
    );

    expect($branch->branches['only'])->toBeInstanceOf(NestedPipeline::class)
        ->and($branch->branches['only']->definition)->toBe($definition);
});

it('rejects an empty branches array with InvalidPipelineDefinition::emptyBranches()', function (): void {
    expect(fn () => ConditionalBranch::fromArray(fn ($ctx) => 'k', []))
        ->toThrow(InvalidPipelineDefinition::class, 'at least one branch entry');
});

it('rejects a ParallelStepGroup branch value via parallelInsideConditionalBranch()', function (): void {
    $parallel = JobPipeline::parallel([FakeJobA::class, FakeJobB::class]);

    expect(fn () => ConditionalBranch::fromArray(fn ($ctx) => 'p', ['p' => $parallel]))
        ->toThrow(InvalidPipelineDefinition::class, 'cannot be ParallelStepGroup');
});

it('rejects a blank or whitespace-only branch key via blankBranchKey()', function (string $key): void {
    expect(fn () => ConditionalBranch::fromArray(fn ($ctx) => 'x', [$key => FakeJobA::class]))
        ->toThrow(InvalidPipelineDefinition::class, 'non-empty, non-whitespace');
})->with(['empty string' => [''], 'whitespace only' => ['   ']]);

it('rejects an integer branch key (auto-indexed array) via blankBranchKey()', function (): void {
    expect(fn () => ConditionalBranch::fromArray(fn ($ctx) => 'x', [FakeJobA::class, FakeJobB::class]))
        ->toThrow(InvalidPipelineDefinition::class, 'non-empty, non-whitespace');
});

it('rejects an unsupported branch value type with a targeted error message', function (): void {
    expect(fn () => ConditionalBranch::fromArray(fn ($ctx) => 'bad', ['bad' => 42]))
        ->toThrow(InvalidPipelineDefinition::class, 'got int for key "bad"');
});

it('is a final class with readonly properties and a private constructor', function (): void {
    $reflection = new ReflectionClass(ConditionalBranch::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->getProperty('selector')->isReadOnly())->toBeTrue()
        ->and($reflection->getProperty('branches')->isReadOnly())->toBeTrue()
        ->and($reflection->getProperty('name')->isReadOnly())->toBeTrue()
        ->and($reflection->getConstructor()?->isPrivate())->toBeTrue();
});
