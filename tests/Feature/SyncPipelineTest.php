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

it('executes all steps in order', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    $builderFactory()
        ->send($context)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(TrackExecutionJobB::class)
        ->step(TrackExecutionJobC::class),
]);

it('gives each job the same PipelineContext instance', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    $result = $builderFactory()
        ->send($context)
        ->run();

    expect($result)->toBe($context);
})->with([
    'array API' => fn () => new PipelineBuilder([
        EnrichContextJob::class,
        ReadContextJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(EnrichContextJob::class)
        ->step(ReadContextJob::class),
]);

it('passes context enriched by step N to step N+1', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->name = 'original';

    $builderFactory()
        ->send($context)
        ->run();

    expect(ReadContextJob::$readName)->toBe('enriched');
})->with([
    'array API' => fn () => new PipelineBuilder([
        EnrichContextJob::class,
        ReadContextJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(EnrichContextJob::class)
        ->step(ReadContextJob::class),
]);

it('stops on first failure and does not execute subsequent steps', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    expect(fn () => $builderFactory()
        ->send($context)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        FailingJob::class,
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobC::class),
]);

it('wraps exception in StepExecutionFailed with pipeline context', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    try {
        $builderFactory()
            ->send($context)
            ->run();

        $this->fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed $exception) {
        expect($exception->getMessage())->toContain('step 1')
            ->and($exception->getMessage())->toContain(FailingJob::class)
            ->and($exception->getMessage())->toContain('Job failed intentionally')
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        FailingJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(FailingJob::class),
]);

it('resolves closure context before first step executes', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->name = 'from-closure';

    $result = $builderFactory()
        ->send(fn () => $context)
        ->run();

    expect($result)->toBe($context)
        ->and(ReadContextJob::$readName)->toBe('from-closure');
})->with([
    'array API' => fn () => new PipelineBuilder([
        ReadContextJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(ReadContextJob::class),
]);

it('returns the final PipelineContext after all steps', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    $result = $builderFactory()
        ->send($context)
        ->run();

    expect($result)
        ->toBeInstanceOf(PipelineContext::class)
        ->toBe($context)
        ->and($result->name)->toBe('enriched');
})->with([
    'array API' => fn () => new PipelineBuilder([
        EnrichContextJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(EnrichContextJob::class),
]);

it('executes steps with null context when no send() is called', function (Closure $builderFactory): void {
    $result = $builderFactory()
        ->run();

    expect($result)->toBeNull()
        ->and(TrackExecutionJob::$executionOrder)->toBe([
            TrackExecutionJobA::class,
            TrackExecutionJobB::class,
        ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(TrackExecutionJobB::class),
]);

it('throws InvalidPipelineDefinition on empty builder', function (): void {
    expect(fn () => (new PipelineBuilder)->run())
        ->toThrow(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');
});
