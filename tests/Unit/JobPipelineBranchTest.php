<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\ConditionalBranch;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\NestedPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;

it('JobPipeline::branch returns a ConditionalBranch with class-string branch values normalized', function (): void {
    $branch = JobPipeline::branch(
        fn ($ctx) => 'a',
        [
            'a' => FakeJobA::class,
            'b' => FakeJobB::class,
        ],
    );

    expect($branch)->toBeInstanceOf(ConditionalBranch::class)
        ->and(array_keys($branch->branches))->toBe(['a', 'b']);
});

it('JobPipeline::branch auto-wraps a nested-pipeline branch value into a NestedPipeline', function (): void {
    $branch = JobPipeline::branch(
        fn ($ctx) => 'premium',
        [
            'standard' => FakeJobA::class,
            'premium' => JobPipeline::make([FakeJobB::class, FakeJobC::class]),
        ],
    );

    expect($branch->branches['premium'])->toBeInstanceOf(NestedPipeline::class)
        ->and($branch->branches['premium']->definition->stepCount())->toBe(2);
});

it('JobPipeline::branch passes the optional name through to ConditionalBranch', function (): void {
    $branch = JobPipeline::branch(
        fn ($ctx) => 'a',
        ['a' => FakeJobA::class],
        'routing',
    );

    expect($branch->name)->toBe('routing');
});
