<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\ConditionalBranch;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;

it('Step::branch returns a ConditionalBranch with class-string branches normalized to StepDefinition', function (): void {
    $branch = Step::branch(
        fn ($ctx) => 'a',
        [
            'a' => FakeJobA::class,
            'b' => FakeJobB::class,
        ],
    );

    expect($branch)->toBeInstanceOf(ConditionalBranch::class)
        ->and($branch->branches['a'])->toBeInstanceOf(StepDefinition::class)
        ->and($branch->branches['a']->jobClass)->toBe(FakeJobA::class);
});

it('Step::branch preserves pre-built StepDefinition branch values with their configuration', function (): void {
    $configured = Step::make(FakeJobA::class)->retry(4);

    $branch = Step::branch(fn ($ctx) => 'a', ['a' => $configured]);

    expect($branch->branches['a'])->toBe($configured)
        ->and($branch->branches['a']->retry)->toBe(4);
});

it('Step::branch passes the optional name through to ConditionalBranch', function (): void {
    $branch = Step::branch(
        fn ($ctx) => 'a',
        ['a' => FakeJobA::class],
        'routing',
    );

    expect($branch->name)->toBe('routing');
});
