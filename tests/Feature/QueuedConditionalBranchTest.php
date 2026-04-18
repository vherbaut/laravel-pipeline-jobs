<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    TrackExecutionJob::$executionOrder = [];
});

it('queued conditional branch selects a flat class-string branch and converges on the next outer step', function (): void {
    $context = new SimpleContext;
    $context->name = 'left';

    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'left' => TrackExecutionJobB::class,
            'right' => FailingJob::class,
        ]),
        TrackExecutionJobC::class,
    ]);

    $builder->send($context)->shouldBeQueued()->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

it('queued conditional branch selects a nested-pipeline branch and runs its inner steps', function (): void {
    $context = new SimpleContext;
    $context->name = 'nested';

    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'flat' => TrackExecutionJobB::class,
            'nested' => JobPipeline::make([TrackExecutionJobB::class, TrackExecutionJobC::class]),
        ]),
        TrackExecutionJobA::class,
    ]);

    $builder->send($context)->shouldBeQueued()->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
        TrackExecutionJobA::class,
    ]);
});

it('queued conditional branch propagates a thrown selector as StepExecutionFailed', function (): void {
    $context = new SimpleContext;

    $builder = new PipelineBuilder([
        Step::branch(
            fn (SimpleContext $ctx) => throw new RuntimeException('queued selector boom'),
            ['k' => TrackExecutionJobA::class],
        ),
    ]);

    try {
        $builder->send($context)->shouldBeQueued()->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed $e) {
        expect($e->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($e->getPrevious()->getMessage())->toBe('queued selector boom');
    }

    expect(TrackExecutionJob::$executionOrder)->toBe([]);
});

it('queued conditional branch propagates an unknown key as StepExecutionFailed', function (): void {
    $context = new SimpleContext;
    $context->name = 'unknown';

    $builder = new PipelineBuilder([
        Step::branch(
            fn (SimpleContext $ctx) => $ctx->name,
            ['a' => TrackExecutionJobA::class, 'b' => TrackExecutionJobB::class],
        ),
    ]);

    try {
        $builder->send($context)->shouldBeQueued()->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed $e) {
        expect($e->getPrevious()->getMessage())->toContain('unknown branch key "unknown"');
    }
});

it('queued conditional branch propagates a non-string selector return as StepExecutionFailed', function (): void {
    $context = new SimpleContext;

    $builder = new PipelineBuilder([
        Step::branch(
            fn (SimpleContext $ctx) => 42,
            ['a' => TrackExecutionJobA::class, 'b' => TrackExecutionJobB::class],
        ),
    ]);

    try {
        $builder->send($context)->shouldBeQueued()->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed $e) {
        expect($e->getPrevious()->getMessage())->toContain('must return a string');
    }
});

it('queued conditional branch advances past a failed branch under SkipAndContinue', function (): void {
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

    $builder->send($context)->shouldBeQueued()->onFailure(FailStrategy::SkipAndContinue)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobC::class,
    ]);
});

it('queued conditional branch continues past a throwing selector under SkipAndContinue', function (): void {
    $context = new SimpleContext;

    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(
            fn (SimpleContext $ctx) => throw new RuntimeException('queued selector boom'),
            ['k' => FailingJob::class],
        ),
        TrackExecutionJobC::class,
    ]);

    $builder->send($context)->shouldBeQueued()->onFailure(FailStrategy::SkipAndContinue)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobC::class,
    ]);
});

it('queued conditional branch continues past an unknown-key selector under SkipAndContinue', function (): void {
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

    $builder->send($context)->shouldBeQueued()->onFailure(FailStrategy::SkipAndContinue)->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobC::class,
    ]);
});

it('queued conditional branch inside a nested pipeline routes through the cursor without losing subsequent inner steps', function (): void {
    $context = new SimpleContext;
    $context->name = 'left';

    $inner = JobPipeline::make([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'left' => TrackExecutionJobB::class,
            'right' => FailingJob::class,
        ]),
        TrackExecutionJobC::class,
    ]);

    (new PipelineBuilder([
        JobPipeline::nest($inner),
        TrackExecutionJobA::class,
    ]))->send($context)->shouldBeQueued()->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
        TrackExecutionJobA::class,
    ]);
});

it('queued conditional branch inside a nested pipeline selects a nested branch value and resumes inner cursor', function (): void {
    $context = new SimpleContext;
    $context->name = 'nested';

    $inner = JobPipeline::make([
        TrackExecutionJobA::class,
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'flat' => TrackExecutionJobB::class,
            'nested' => JobPipeline::make([TrackExecutionJobB::class, TrackExecutionJobC::class]),
        ]),
        TrackExecutionJobA::class,
    ]);

    (new PipelineBuilder([
        JobPipeline::nest($inner),
        TrackExecutionJobC::class,
    ]))->send($context)->shouldBeQueued()->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
        TrackExecutionJobA::class,
        TrackExecutionJobC::class,
    ]);
});

it('queued conditional branch fires pipeline onFailure callback on selector failure', function (): void {
    $context = new SimpleContext;
    Cache::forget('queued-branch-onfailure-captured');

    try {
        JobPipeline::make([
            Step::branch(
                fn (SimpleContext $ctx) => throw new RuntimeException('queued selector boom'),
                ['k' => TrackExecutionJobA::class],
            ),
        ])
            ->onFailure(function (SimpleContext $ctx, Throwable $cause): void {
                Cache::put('queued-branch-onfailure-captured', $cause->getMessage(), 60);
            })
            ->send($context)
            ->shouldBeQueued()
            ->run();
        expect(false)->toBeTrue('expected StepExecutionFailed');
    } catch (StepExecutionFailed) {
        // expected
    }

    expect(Cache::get('queued-branch-onfailure-captured'))->toBe('queued selector boom');
});
