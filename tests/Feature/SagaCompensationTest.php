<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ContractBasedCompensation;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingCompensationJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    CompensateJobA::$executed = [];
    CompensateJobA::$observedName = null;
    CompensateJobB::$executed = [];
    CompensateJobC::$executed = [];
    ContractBasedCompensation::$received = [];
    FailingCompensationJob::$executed = [];
    TrackExecutionJob::$executionOrder = [];
});

it('runs reverse-order compensation in sync mode under StopAndCompensate', function (): void {
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ])
        ->and(CompensateJobB::$executed)->toBe([CompensateJobB::class])
        ->and(CompensateJobA::$executed)->toBe([CompensateJobA::class]);
});

it('does not run compensation in sync mode under StopImmediately even when compensateWith is defined', function (): void {
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(CompensateJobA::$executed)->toBe([]);
});

it('does not run compensation in sync mode under SkipAndContinue', function (): void {
    // Story 5.2 treats SkipAndContinue identically to StopImmediately for
    // compensation dispatch. Step-level skipping is Story 5.3.
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(CompensateJobA::$executed)->toBe([]);
});

it('does not invoke the compensation of the failing step itself', function (): void {
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)->compensateWith(CompensateJobC::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(CompensateJobA::$executed)->toBe([CompensateJobA::class])
        ->and(CompensateJobC::$executed)->toBe([]);
});

it('skips completed steps without compensation in the chain', function (): void {
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class) // no compensateWith
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(CompensateJobA::$executed)->toBe([CompensateJobA::class])
        ->and(CompensateJobB::$executed)->toBe([]);
});

it('continues the compensation chain when one compensation throws', function (): void {
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(FailingCompensationJob::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    // FailingCompensationJob runs first (reverse order), throws, but the chain continues.
    expect(FailingCompensationJob::$executed)->toBe([FailingCompensationJob::class])
        ->and(CompensateJobA::$executed)->toBe([CompensateJobA::class]);
});

it('invokes compensate() on CompensableJob-implementing compensations alongside trait-based ones', function (): void {
    $context = new SimpleContext;
    $context->name = 'saga-state';

    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(ContractBasedCompensation::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send($context)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    // Reverse order: ContractBasedCompensation runs first (via compensate()),
    // then CompensateJobA (via handle() with trait-injected manifest).
    expect(ContractBasedCompensation::$received)->toBe([SimpleContext::class])
        ->and(CompensateJobA::$executed)->toBe([CompensateJobA::class])
        ->and(CompensateJobA::$observedName)->toBe('saga-state');
});
