<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\ParallelStepGroup;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;

it('constructs a parallel group from class-string entries', function (): void {
    $group = ParallelStepGroup::fromArray([FakeJobA::class, FakeJobB::class]);

    expect($group)->toBeInstanceOf(ParallelStepGroup::class)
        ->and($group->steps)->toHaveCount(2)
        ->and($group->steps[0])->toBeInstanceOf(StepDefinition::class)
        ->and($group->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($group->steps[1]->jobClass)->toBe(FakeJobB::class);
});

it('constructs a parallel group from pre-built StepDefinition entries preserving configuration', function (): void {
    $stepA = Step::make(FakeJobA::class)->retry(3);
    $stepB = Step::make(FakeJobB::class)->onQueue('emails');

    $group = ParallelStepGroup::fromArray([$stepA, $stepB]);

    expect($group->steps)->toHaveCount(2)
        ->and($group->steps[0])->toBe($stepA)
        ->and($group->steps[0]->retry)->toBe(3)
        ->and($group->steps[1])->toBe($stepB)
        ->and($group->steps[1]->queue)->toBe('emails');
});

it('accepts a mixed list of class-strings and StepDefinition instances', function (): void {
    $stepB = Step::make(FakeJobB::class)->timeout(45);

    $group = ParallelStepGroup::fromArray([FakeJobA::class, $stepB, FakeJobC::class]);

    expect($group->steps)->toHaveCount(3)
        ->and($group->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($group->steps[0]->timeout)->toBeNull()
        ->and($group->steps[1])->toBe($stepB)
        ->and($group->steps[2]->jobClass)->toBe(FakeJobC::class);
});

it('throws emptyParallelGroup InvalidPipelineDefinition on an empty array', function (): void {
    expect(fn () => ParallelStepGroup::fromArray([]))
        ->toThrow(
            InvalidPipelineDefinition::class,
            'Parallel step group must contain at least one sub-step; got an empty array. Call JobPipeline::parallel([JobA::class, JobB::class, ...]) with one or more sub-steps.',
        );
});

it('throws InvalidPipelineDefinition with the offending type named for non-string non-StepDefinition items', function (): void {
    expect(fn () => ParallelStepGroup::fromArray([FakeJobA::class, 42]))
        ->toThrow(InvalidPipelineDefinition::class);

    try {
        ParallelStepGroup::fromArray([FakeJobA::class, 42]);
    } catch (InvalidPipelineDefinition $exception) {
        expect($exception->getMessage())->toContain('int');
    }
});

it('is a final class with a readonly steps property and a private constructor', function (): void {
    $reflection = new ReflectionClass(ParallelStepGroup::class);

    expect($reflection->isFinal())->toBeTrue();

    $stepsProperty = $reflection->getProperty('steps');
    expect($stepsProperty->isReadOnly())->toBeTrue();

    expect($reflection->getConstructor()?->isPrivate())->toBeTrue();
});
