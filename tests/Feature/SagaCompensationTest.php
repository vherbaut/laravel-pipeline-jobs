<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ContractBasedCompensation;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingCompensationJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailureContextRecordingCompensation;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ManifestSnapshotObserverJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TraitFailureRecordingCompensation;

beforeEach(function (): void {
    CompensateJobA::$executed = [];
    CompensateJobA::$observedName = null;
    CompensateJobB::$executed = [];
    CompensateJobC::$executed = [];
    ContractBasedCompensation::$received = [];
    FailingCompensationJob::$executed = [];
    FailureContextRecordingCompensation::$lastFailure = null;
    ManifestSnapshotObserverJob::reset();
    TrackExecutionJob::$executionOrder = [];
    TraitFailureRecordingCompensation::$lastFailure = null;
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

it('skips the failed step and continues to the next step under SkipAndContinue without running compensation', function (): void {
    $context = new SimpleContext;

    $result = (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobB::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->send($context)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ])
        ->and(CompensateJobA::$executed)->toBe([])
        ->and($result)->toBe($context);
});

it('logs a warning when a step is skipped under SkipAndContinue', function (): void {
    Log::spy();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobB::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->send(new SimpleContext)
        ->run();

    Log::shouldHaveReceived('warning')
        ->with('Pipeline step skipped under SkipAndContinue', Mockery::on(function (array $context): bool {
            return $context['stepClass'] === FailingJob::class
                && $context['stepIndex'] === 1
                && is_string($context['pipelineId'])
                && is_string($context['exception']);
        }));
});

it('preserves context mutations across a skipped step under SkipAndContinue', function (): void {
    $context = new SimpleContext;
    $context->name = 'initial';

    $result = (new PipelineBuilder)
        ->step(EnrichContextJob::class)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobB::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->send($context)
        ->run();

    expect($result)->toBe($context)
        ->and($context->name)->toBe('enriched');
});

it('clears failure context fields on a successful step after a skipped step under SkipAndContinue', function (): void {
    (new PipelineBuilder)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobA::class)
        ->step(ManifestSnapshotObserverJob::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->send(new SimpleContext)
        ->run();

    expect(ManifestSnapshotObserverJob::$observed)->toBeTrue()
        ->and(ManifestSnapshotObserverJob::$failedStepClass)->toBeNull()
        ->and(ManifestSnapshotObserverJob::$failedStepIndex)->toBeNull()
        ->and(ManifestSnapshotObserverJob::$failureException)->toBeNull();
});

it('overwrites failure context with last-failure-wins under SkipAndContinue when multiple steps fail', function (): void {
    (new PipelineBuilder)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobA::class)
        ->step(FailingJob::class)
        ->step(ManifestSnapshotObserverJob::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->send(new SimpleContext)
        ->run();

    expect(ManifestSnapshotObserverJob::$observed)->toBeTrue()
        ->and(ManifestSnapshotObserverJob::$failedStepClass)->toBe(FailingJob::class)
        ->and(ManifestSnapshotObserverJob::$failedStepIndex)->toBe(2);
});

it('does not record a skipped step in completedSteps under SkipAndContinue', function (): void {
    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobB::class)
        ->step(ManifestSnapshotObserverJob::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->send(new SimpleContext)
        ->run();

    expect(ManifestSnapshotObserverJob::$observed)->toBeTrue()
        ->and(ManifestSnapshotObserverJob::$completedSteps)->toBe([
            TrackExecutionJobA::class,
            TrackExecutionJobB::class,
        ]);
});

it('emits one warning per skipped step when multiple steps fail under SkipAndContinue', function (): void {
    Log::spy();

    (new PipelineBuilder)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobA::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->send(new SimpleContext)
        ->run();

    Log::shouldHaveReceived('warning')
        ->with('Pipeline step skipped under SkipAndContinue', Mockery::type('array'))
        ->twice();
});

it('handles SkipAndContinue on the final step without throwing in sync mode', function (): void {
    $context = new SimpleContext;

    $result = (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->send($context)
        ->run();

    expect($result)->toBe($context)
        ->and(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);
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

it('exposes a populated FailureContext to a CompensableJob via the second compensate() argument in sync mode', function (): void {
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(FailureContextRecordingCompensation::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    $failure = FailureContextRecordingCompensation::$lastFailure;

    expect($failure)->toBeInstanceOf(FailureContext::class)
        ->and($failure?->failedStepClass)->toBe(FailingJob::class)
        ->and($failure?->failedStepIndex)->toBe(1)
        ->and($failure?->exception)->toBeInstanceOf(RuntimeException::class);
});

it('exposes failure context to a trait-based compensation job via the trait accessor in sync mode', function (): void {
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(TraitFailureRecordingCompensation::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    $failure = TraitFailureRecordingCompensation::$lastFailure;

    expect($failure)->toBeInstanceOf(FailureContext::class)
        ->and($failure?->failedStepClass)->toBe(FailingJob::class)
        ->and($failure?->exception)->toBeInstanceOf(RuntimeException::class);
});

it('logs an error and dispatches CompensationFailed when a sync compensation throws', function (): void {
    Log::spy();
    Event::fake([CompensationFailedEvent::class]);

    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(FailingCompensationJob::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    Log::shouldHaveReceived('error')
        ->with('Pipeline compensation failed', Mockery::on(function (array $context): bool {
            return $context['compensationClass'] === FailingCompensationJob::class
                && $context['failedStepClass'] === FailingJob::class;
        }));

    Event::assertDispatched(
        CompensationFailedEvent::class,
        fn (CompensationFailedEvent $event): bool => $event->compensationClass === FailingCompensationJob::class
            && $event->failedStepClass === FailingJob::class
            && $event->originalException instanceof RuntimeException,
    );
});
