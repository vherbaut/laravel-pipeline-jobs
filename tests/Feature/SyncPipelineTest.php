<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
});

it('executes all steps in order', function (): void {
    $context = new SimpleContext;

    $result = (new PipelineBuilder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]))
        ->send($context)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

it('gives each job the same PipelineContext instance', function (): void {
    $context = new SimpleContext;

    $result = (new PipelineBuilder([
        EnrichContextJob::class,
        ReadContextJob::class,
    ]))
        ->send($context)
        ->run();

    expect($result)->toBe($context);
});

it('passes context enriched by step N to step N+1', function (): void {
    $context = new SimpleContext;
    $context->name = 'original';

    (new PipelineBuilder([
        EnrichContextJob::class,
        ReadContextJob::class,
    ]))
        ->send($context)
        ->run();

    expect(ReadContextJob::$readName)->toBe('enriched');
});

it('stops on first failure and does not execute subsequent steps', function (): void {
    $context = new SimpleContext;

    expect(fn () => (new PipelineBuilder([
        TrackExecutionJobA::class,
        FailingJob::class,
        TrackExecutionJobC::class,
    ]))
        ->send($context)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
    ]);
});

it('wraps exception in StepExecutionFailed with pipeline context', function (): void {
    $context = new SimpleContext;

    try {
        (new PipelineBuilder([
            TrackExecutionJobA::class,
            FailingJob::class,
        ]))
            ->send($context)
            ->run();

        $this->fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed $exception) {
        expect($exception->getMessage())->toContain('step 1')
            ->and($exception->getMessage())->toContain(FailingJob::class)
            ->and($exception->getMessage())->toContain('Job failed intentionally')
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});

it('resolves closure context before first step executes', function (): void {
    $context = new SimpleContext;
    $context->name = 'from-closure';

    $result = (new PipelineBuilder([
        ReadContextJob::class,
    ]))
        ->send(fn () => $context)
        ->run();

    expect($result)->toBe($context)
        ->and(ReadContextJob::$readName)->toBe('from-closure');
});

it('returns the final PipelineContext after all steps', function (): void {
    $context = new SimpleContext;

    $result = (new PipelineBuilder([
        EnrichContextJob::class,
    ]))
        ->send($context)
        ->run();

    expect($result)
        ->toBeInstanceOf(PipelineContext::class)
        ->toBe($context)
        ->and($result->name)->toBe('enriched');
});

it('executes steps with null context when no send() is called', function (): void {
    $result = (new PipelineBuilder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]))
        ->run();

    expect($result)->toBeNull()
        ->and(TrackExecutionJob::$executionOrder)->toBe([
            TrackExecutionJobA::class,
            TrackExecutionJobB::class,
        ]);
});

it('throws InvalidPipelineDefinition on empty builder', function (): void {
    expect(fn () => (new PipelineBuilder)->run())
        ->toThrow(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');
});
