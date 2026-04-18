<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];
});

it('routes to the "left" branch when the selector returns "left" (sync)', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->name = 'left';

    $builderFactory()->send($context)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'left' => TrackExecutionJobB::class,
            'right' => FailingJob::class,
        ]),
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->branch(fn (SimpleContext $ctx) => $ctx->name, [
            'left' => TrackExecutionJobB::class,
            'right' => FailingJob::class,
        ])
        ->step(TrackExecutionJobC::class),
]);

it('routes to the other branch when the selector returns a different key (sync)', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->name = 'right';

    $builderFactory()->send($context)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobC::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'left' => TrackExecutionJobB::class,
            'right' => TrackExecutionJobC::class,
        ]),
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->branch(fn (SimpleContext $ctx) => $ctx->name, [
            'left' => TrackExecutionJobB::class,
            'right' => TrackExecutionJobC::class,
        ]),
]);

it('executes a nested-pipeline branch value sequentially and converges on the next outer step (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'nested';

    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'flat' => TrackExecutionJobB::class,
            'nested' => JobPipeline::make([TrackExecutionJobB::class, EnrichContextJob::class]),
        ]),
        TrackExecutionJobC::class,
    ]);

    $result = $builder->send($context)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ])->and($result->name)->toBe('enriched');
});

it('supports branch-inside-nested composition (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'left';

    $inner = JobPipeline::make([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'left' => TrackExecutionJobB::class,
            'right' => FailingJob::class,
        ]),
    ]);

    $builder = new PipelineBuilder([
        JobPipeline::nest($inner),
        TrackExecutionJobC::class,
    ]);

    $builder->send($context)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

it('rejects a ConditionalBranch inside a ParallelStepGroup at build time', function (): void {
    expect(fn () => JobPipeline::parallel([
        TrackExecutionJobA::class,
        Step::branch(fn ($ctx) => 'k', ['k' => TrackExecutionJobB::class]),
    ]))->toThrow(InvalidPipelineDefinition::class, 'Conditional branches cannot be embedded inside parallel step groups');
});

it('rejects a ParallelStepGroup as a branch value at factory time', function (): void {
    expect(fn () => Step::branch(fn ($ctx) => 'k', [
        'k' => JobPipeline::parallel([TrackExecutionJobA::class]),
    ]))->toThrow(InvalidPipelineDefinition::class, 'cannot be ParallelStepGroup');
});

it('wraps a throwing selector closure as StepExecutionFailed with the original as previous (sync)', function (): void {
    $context = new SimpleContext;

    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(
            fn (SimpleContext $ctx) => throw new RuntimeException('selector boom'),
            ['k' => TrackExecutionJobB::class],
        ),
    ]);

    try {
        $builder->send($context)->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed $e) {
        expect($e->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($e->getPrevious()->getMessage())->toBe('selector boom');
    }
});

it('wraps a non-string selector return as InvalidPipelineDefinition::branchSelectorMustReturnString (sync)', function (): void {
    $context = new SimpleContext;

    $builder = new PipelineBuilder([
        Step::branch(
            fn (SimpleContext $ctx) => 42,
            ['a' => TrackExecutionJobA::class, 'b' => TrackExecutionJobB::class],
        ),
    ]);

    try {
        $builder->send($context)->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed $e) {
        expect($e->getPrevious())->toBeInstanceOf(InvalidPipelineDefinition::class)
            ->and($e->getPrevious()->getMessage())->toContain('must return a string')
            ->and($e->getPrevious()->getMessage())->toContain('int');
    }
});

it('wraps an unknown branch key as InvalidPipelineDefinition::unknownBranchKey (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'missing';

    $builder = new PipelineBuilder([
        Step::branch(
            fn (SimpleContext $ctx) => $ctx->name,
            ['a' => TrackExecutionJobA::class, 'b' => TrackExecutionJobB::class],
        ),
    ]);

    try {
        $builder->send($context)->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed $e) {
        expect($e->getPrevious())->toBeInstanceOf(InvalidPipelineDefinition::class)
            ->and($e->getPrevious()->getMessage())->toContain('unknown branch key "missing"')
            ->and($e->getPrevious()->getMessage())->toContain('"a"')
            ->and($e->getPrevious()->getMessage())->toContain('"b"');
    }
});

it('halts on selected-branch inner failure under StopImmediately (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'bad';

    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'good' => TrackExecutionJobB::class,
            'bad' => FailingJob::class,
        ]),
        TrackExecutionJobC::class,
    ]);

    expect(fn () => $builder->send($context)->run())
        ->toThrow(StepExecutionFailed::class);

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);
});

it('compensates the selected branch inner step under StopAndCompensate (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'bad';

    $builder = new PipelineBuilder([
        Step::make(TrackExecutionJobA::class)->withCompensation(CompensateJobA::class),
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'good' => Step::make(TrackExecutionJobB::class)->withCompensation(CompensateJobB::class),
            'bad' => FailingJob::class,
        ]),
    ]);

    try {
        $builder->send($context)->onFailure(FailStrategy::StopAndCompensate)->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed) {
        // expected
    }

    expect(CompensateJobA::$executed)->not->toBeEmpty();
});

it('continues past a failed branch under SkipAndContinue (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'bad';

    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'good' => TrackExecutionJobB::class,
            'bad' => FailingJob::class,
        ]),
        TrackExecutionJobC::class,
    ]);

    $builder->send($context)->onFailure(FailStrategy::SkipAndContinue)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobC::class,
    ]);
});

it('continues past a throwing selector under SkipAndContinue (sync)', function (): void {
    $context = new SimpleContext;

    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(
            fn (SimpleContext $ctx) => throw new RuntimeException('selector boom'),
            ['k' => FailingJob::class],
        ),
        TrackExecutionJobC::class,
    ]);

    $builder->send($context)->onFailure(FailStrategy::SkipAndContinue)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobC::class,
    ]);
});

it('continues past an unknown-key selector under SkipAndContinue (sync)', function (): void {
    $context = new SimpleContext;
    $context->name = 'missing';

    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(
            fn (SimpleContext $ctx) => $ctx->name,
            ['a' => FailingJob::class, 'b' => FailingJob::class],
        ),
        TrackExecutionJobC::class,
    ]);

    $builder->send($context)->onFailure(FailStrategy::SkipAndContinue)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobC::class,
    ]);
});

it('compensates prior completed steps on selector failure under StopAndCompensate (sync)', function (): void {
    $context = new SimpleContext;

    $builder = new PipelineBuilder([
        Step::make(TrackExecutionJobA::class)->withCompensation(CompensateJobA::class),
        Step::branch(
            fn (SimpleContext $ctx) => throw new RuntimeException('selector boom'),
            ['k' => TrackExecutionJobB::class],
        ),
    ]);

    try {
        $builder->send($context)->onFailure(FailStrategy::StopAndCompensate)->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed) {
        // expected
    }

    expect(CompensateJobA::$executed)->toBe([CompensateJobA::class]);
});

it('fires pipeline onFailure callback on selector failure (sync)', function (): void {
    $context = new SimpleContext;
    $captured = [];

    try {
        JobPipeline::make([
            Step::branch(
                fn (SimpleContext $ctx) => throw new RuntimeException('selector boom'),
                ['k' => TrackExecutionJobA::class],
            ),
        ])
            ->onFailure(function (SimpleContext $ctx, Throwable $cause) use (&$captured): void {
                $captured[] = $cause->getMessage();
            })
            ->send($context)
            ->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed) {
        // expected
    }

    expect($captured)->toBe(['selector boom']);
});
