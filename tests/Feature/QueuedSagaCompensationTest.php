<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Execution\CompensationStepJob;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailureContextRecordingCompensation;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];
    FailureContextRecordingCompensation::$lastFailure = null;
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

it('does not mark the wrapper as failed when a step throws under SkipAndContinue', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class],
        context: new SimpleContext,
        failStrategy: FailStrategy::SkipAndContinue,
    );
    $manifest->completedSteps = [TrackExecutionJobA::class];
    $manifest->currentStepIndex = 1;

    expect(fn () => (new PipelineStepJob($manifest))->handle())->not->toThrow(Throwable::class);
});

it('dispatches the next PipelineStepJob after skipping a failed step under SkipAndContinue', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class],
        context: new SimpleContext,
        failStrategy: FailStrategy::SkipAndContinue,
    );
    $manifest->completedSteps = [TrackExecutionJobA::class];
    $manifest->currentStepIndex = 1;

    (new PipelineStepJob($manifest))->handle();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->manifest->currentStepIndex === 2,
    );
});

it('logs a warning per skipped step in queued mode under SkipAndContinue', function (): void {
    Bus::fake();
    Log::spy();

    $manifest = PipelineManifest::create(
        stepClasses: [FailingJob::class, TrackExecutionJobB::class],
        context: new SimpleContext,
        failStrategy: FailStrategy::SkipAndContinue,
    );
    $manifest->currentStepIndex = 0;

    (new PipelineStepJob($manifest))->handle();

    Log::shouldHaveReceived('warning')
        ->with('Pipeline step skipped under SkipAndContinue', Mockery::on(function (array $context): bool {
            return $context['stepClass'] === FailingJob::class
                && $context['stepIndex'] === 0;
        }));
});

it('does not dispatch any compensation chain in queued mode under SkipAndContinue', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class],
        context: new SimpleContext,
        compensationMapping: [TrackExecutionJobA::class => CompensateJobA::class],
        failStrategy: FailStrategy::SkipAndContinue,
    );
    $manifest->completedSteps = [TrackExecutionJobA::class];
    $manifest->currentStepIndex = 1;

    (new PipelineStepJob($manifest))->handle();

    Bus::assertNotDispatched(CompensationStepJob::class);
});

it('clears manifest->failureException before dispatching the next PipelineStepJob under SkipAndContinue', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [FailingJob::class, TrackExecutionJobB::class],
        context: new SimpleContext,
        failStrategy: FailStrategy::SkipAndContinue,
    );
    $manifest->currentStepIndex = 0;

    (new PipelineStepJob($manifest))->handle();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->manifest->failureException === null,
    );
});

it('does not re-dispatch when the final step fails under SkipAndContinue in queued mode', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class],
        context: new SimpleContext,
        failStrategy: FailStrategy::SkipAndContinue,
    );
    $manifest->completedSteps = [TrackExecutionJobA::class];
    $manifest->currentStepIndex = 1;

    expect(fn () => (new PipelineStepJob($manifest))->handle())->not->toThrow(Throwable::class);

    Bus::assertNotDispatched(PipelineStepJob::class);
});

it('exposes failure context to CompensationStepJob handlers across serialization with exception nulled per NFR19', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class],
        context: new SimpleContext,
        compensationMapping: [TrackExecutionJobA::class => FailureContextRecordingCompensation::class],
        failStrategy: FailStrategy::StopAndCompensate,
    );
    $manifest->failedStepClass = FailingJob::class;
    $manifest->failedStepIndex = 1;
    // In production the Throwable is nulled before Bus::chain() serializes the
    // wrapper; emulate the queued payload state here.
    $manifest->failureException = null;

    $wrapper = new CompensationStepJob(FailureContextRecordingCompensation::class, $manifest);

    // Round-trip through PHP's native serialization boundary that Laravel's
    // queue uses, to prove failedStepClass survives and the Throwable does not.
    $roundTripped = unserialize(serialize($wrapper));
    $roundTripped->handle();

    $failure = FailureContextRecordingCompensation::$lastFailure;

    expect($failure)->not->toBeNull()
        ->and($failure?->failedStepClass)->toBe(FailingJob::class)
        ->and($failure?->failedStepIndex)->toBe(1)
        ->and($failure?->exception)->toBeNull();
});
