<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\ParallelStepGroup;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;

it('returns a ParallelStepGroup instance from JobPipeline::parallel()', function (): void {
    $group = JobPipeline::parallel([FakeJobA::class, FakeJobB::class]);

    expect($group)->toBeInstanceOf(ParallelStepGroup::class)
        ->and($group->steps)->toHaveCount(2);
});

it('threads class-string entries to the resulting ParallelStepGroup', function (): void {
    $group = JobPipeline::parallel([FakeJobA::class, FakeJobB::class]);

    expect($group->steps[0])->toBeInstanceOf(StepDefinition::class)
        ->and($group->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($group->steps[1]->jobClass)->toBe(FakeJobB::class);
});

it('surfaces InvalidPipelineDefinition from JobPipeline::parallel() on empty array', function (): void {
    expect(fn () => JobPipeline::parallel([]))
        ->toThrow(InvalidPipelineDefinition::class, 'Parallel step group must contain at least one sub-step');
});

it('surfaces InvalidPipelineDefinition from JobPipeline::parallel() on unsupported item types', function (): void {
    expect(fn () => JobPipeline::parallel([FakeJobA::class, 123]))
        ->toThrow(InvalidPipelineDefinition::class);
});
