<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Execution\CompensationStepJob;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];
    TrackExecutionJob::$executionOrder = [];
});

it('dispatches a reversed Bus::chain of CompensationStepJob when a queued step fails under StopAndCompensate', function (): void {
    Bus::fake();

    // Prepare a manifest with two already-completed steps and a third step about to fail.
    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, TrackExecutionJobB::class, FailingJob::class],
        context: new SimpleContext,
        compensationMapping: [
            TrackExecutionJobA::class => CompensateJobA::class,
            TrackExecutionJobB::class => CompensateJobB::class,
        ],
        failStrategy: FailStrategy::StopAndCompensate,
    );
    $manifest->completedSteps = [TrackExecutionJobA::class, TrackExecutionJobB::class];
    $manifest->currentStepIndex = 2;

    expect(fn () => (new PipelineStepJob($manifest))->handle())
        ->toThrow(RuntimeException::class, 'Job failed intentionally');

    // Reverse order: B compensates first (B completed last), then A.
    Bus::assertChained([
        fn (CompensationStepJob $job): bool => $job->compensationClass === CompensateJobB::class,
        fn (CompensationStepJob $job): bool => $job->compensationClass === CompensateJobA::class,
    ]);
});

it('does not dispatch any compensation chain in queued mode when strategy is StopImmediately', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class],
        context: new SimpleContext,
        compensationMapping: [TrackExecutionJobA::class => CompensateJobA::class],
        // Default strategy is StopImmediately.
    );
    $manifest->completedSteps = [TrackExecutionJobA::class];
    $manifest->currentStepIndex = 1;

    expect(fn () => (new PipelineStepJob($manifest))->handle())
        ->toThrow(RuntimeException::class, 'Job failed intentionally');

    Bus::assertNotDispatched(CompensationStepJob::class);
});

it('does not dispatch the failing step own compensation when it is declared (AC #3)', function (): void {
    Bus::fake();

    // Failing step itself declares a compensation; because it never completes,
    // that compensation must NOT appear in the dispatched reverse chain.
    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class],
        context: new SimpleContext,
        compensationMapping: [
            TrackExecutionJobA::class => CompensateJobA::class,
            FailingJob::class => CompensateJobB::class,
        ],
        failStrategy: FailStrategy::StopAndCompensate,
    );
    $manifest->completedSteps = [TrackExecutionJobA::class];
    $manifest->currentStepIndex = 1;

    expect(fn () => (new PipelineStepJob($manifest))->handle())
        ->toThrow(RuntimeException::class);

    // Only CompensateJobA must be chained; CompensateJobB (failing step's own
    // compensation) must never be dispatched.
    Bus::assertChained([
        fn (CompensationStepJob $job): bool => $job->compensationClass === CompensateJobA::class,
    ]);
    Bus::assertDispatched(
        CompensationStepJob::class,
        fn (CompensationStepJob $job): bool => $job->compensationClass === CompensateJobA::class,
    );
    Bus::assertNotDispatched(
        CompensationStepJob::class,
        fn (CompensationStepJob $job): bool => $job->compensationClass === CompensateJobB::class,
    );
});

it('dispatches no compensation chain when the first step fails and completedSteps is empty', function (): void {
    Bus::fake();

    // First step fails; no step ever completed, so even though StopAndCompensate
    // is active and a mapping exists, the chain must short-circuit to no dispatch.
    $manifest = PipelineManifest::create(
        stepClasses: [FailingJob::class, TrackExecutionJobB::class],
        context: new SimpleContext,
        compensationMapping: [TrackExecutionJobB::class => CompensateJobB::class],
        failStrategy: FailStrategy::StopAndCompensate,
    );
    $manifest->completedSteps = [];
    $manifest->currentStepIndex = 0;

    expect(fn () => (new PipelineStepJob($manifest))->handle())
        ->toThrow(RuntimeException::class, 'Job failed intentionally');

    Bus::assertNotDispatched(CompensationStepJob::class);
});

it('clears manifest->failureException before dispatching the compensation chain for serialization safety', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class],
        context: new SimpleContext,
        compensationMapping: [TrackExecutionJobA::class => CompensateJobA::class],
        failStrategy: FailStrategy::StopAndCompensate,
    );
    $manifest->completedSteps = [TrackExecutionJobA::class];
    $manifest->currentStepIndex = 1;

    expect(fn () => (new PipelineStepJob($manifest))->handle())
        ->toThrow(RuntimeException::class);

    // The wrapped CompensationStepJob shares the manifest reference; its serialized
    // payload must not carry the Throwable because Throwable::getTrace() holds
    // non-serializable references.
    Bus::assertDispatched(
        CompensationStepJob::class,
        fn (CompensationStepJob $job): bool => $job->manifest->failureException === null,
    );
});
