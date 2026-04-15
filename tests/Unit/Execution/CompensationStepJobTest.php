<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;
use Vherbaut\LaravelPipelineJobs\Execution\CompensationStepJob;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ContractBasedCompensation;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingCompensationJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;

beforeEach(function (): void {
    CompensateJobA::$executed = [];
    CompensateJobA::$observedName = null;
    ContractBasedCompensation::$received = [];
    FailingCompensationJob::$executed = [];
});

it('stores the compensation class and manifest on construction', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        context: new SimpleContext,
    );

    $job = new CompensationStepJob(CompensateJobA::class, $manifest);

    expect($job->compensationClass)->toBe(CompensateJobA::class)
        ->and($job->manifest)->toBe($manifest);
});

it('defaults tries to 1', function (): void {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\StepOne']);

    $job = new CompensationStepJob(CompensateJobA::class, $manifest);

    expect($job->tries)->toBe(1);
});

it('invokes compensate() on a CompensableJob implementation without touching handle()', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        context: new SimpleContext,
    );

    (new CompensationStepJob(ContractBasedCompensation::class, $manifest))->handle();

    expect(ContractBasedCompensation::$received)->toBe([SimpleContext::class]);
});

it('invokes handle() via the container for trait-based compensations', function (): void {
    $context = new SimpleContext;
    $context->name = 'pre-fail';

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\StepOne'],
        context: $context,
    );

    (new CompensationStepJob(CompensateJobA::class, $manifest))->handle();

    expect(CompensateJobA::$executed)->toBe([CompensateJobA::class])
        ->and(CompensateJobA::$observedName)->toBe('pre-fail');
});

it('propagates exceptions thrown by the compensation job', function (): void {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\StepOne']);

    $job = new CompensationStepJob(FailingCompensationJob::class, $manifest);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Compensation job failed intentionally');
});

it('dispatches a CompensationFailed event from failed() with the full context payload', function (): void {
    Event::fake([CompensationFailedEvent::class]);

    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\StepOne']);
    $manifest->failedStepClass = 'App\\Jobs\\StepOne';
    $manifest->failedStepIndex = 0;

    $exception = new RuntimeException('compensation blew up');
    $job = new CompensationStepJob(CompensateJobA::class, $manifest);

    $job->failed($exception);

    Event::assertDispatched(
        CompensationFailedEvent::class,
        fn (CompensationFailedEvent $event): bool => $event->pipelineId === $manifest->pipelineId
            && $event->compensationClass === CompensateJobA::class
            && $event->failedStepClass === 'App\\Jobs\\StepOne'
            && $event->originalException === null
            && $event->compensationException === $exception,
    );
});

it('logs an error from failed() with pipeline metadata', function (): void {
    Log::spy();

    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\StepOne']);
    $manifest->failedStepClass = 'App\\Jobs\\StepOne';
    $manifest->failedStepIndex = 0;

    $exception = new RuntimeException('compensation blew up');
    $job = new CompensationStepJob(CompensateJobA::class, $manifest);

    $job->failed($exception);

    Log::shouldHaveReceived('error')
        ->with('Pipeline compensation failed after retries', Mockery::on(function (array $context) use ($manifest): bool {
            return $context['pipelineId'] === $manifest->pipelineId
                && $context['compensationClass'] === CompensateJobA::class
                && $context['failedStepClass'] === 'App\\Jobs\\StepOne'
                && $context['compensationException'] === 'compensation blew up';
        }));
});

it('forwards a null failedStepClass when the manifest has no failure recorded in failed()', function (): void {
    Event::fake([CompensationFailedEvent::class]);

    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\StepOne']);

    $job = new CompensationStepJob(CompensateJobA::class, $manifest);

    $job->failed(new RuntimeException('boom'));

    Event::assertDispatched(
        CompensationFailedEvent::class,
        fn (CompensationFailedEvent $event): bool => $event->failedStepClass === null,
    );
});

it('dispatches the compensation chain on the Laravel default queue regardless of per-step queue config (AC #13 regression guard)', function (): void {
    // Build a manifest where step 0 already completed, then step 1 fails
    // with per-step queue config of 'heavy' / 'redis'. The compensation
    // chain MUST dispatch CompensateJobA on the default queue (queue ===
    // null on the wrapped jobs), not pick up the failing step's queue.
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class],
        context: new SimpleContext,
        compensationMapping: [TrackExecutionJobA::class => CompensateJobA::class],
        failStrategy: FailStrategy::StopAndCompensate,
        stepConfigs: [
            0 => ['queue' => null, 'connection' => null, 'sync' => false],
            1 => ['queue' => 'heavy', 'connection' => 'redis', 'sync' => false],
        ],
    );

    // Pre-mark step 0 as completed and advance the index so the next handle()
    // invocation lands on the failing step.
    $manifest->markStepCompleted(TrackExecutionJobA::class);
    $manifest->advanceStep();

    try {
        (new PipelineStepJob($manifest))->handle();
    } catch (Throwable) {
        // FailingJob throws under StopAndCompensate; we expect the rethrow.
    }

    Bus::assertChained([
        function (CompensationStepJob $job): bool {
            // The CompensationStepJob's queue and connection are unset
            // (default Laravel routing) — the failing step's 'heavy' / 'redis'
            // override is NOT carried into the compensation chain.
            return $job->compensationClass === CompensateJobA::class
                && $job->queue === null
                && $job->connection === null;
        },
    ]);
});

it('CompensationStepJob dispatched from a pipeline with retry/timeout on the failing step does NOT carry retry or timeout onto the compensation wrapper', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, FailingJob::class],
        context: new SimpleContext,
        compensationMapping: [TrackExecutionJobA::class => CompensateJobA::class],
        failStrategy: FailStrategy::StopAndCompensate,
        stepConfigs: [
            0 => ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
            1 => ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => 3, 'backoff' => 5, 'timeout' => 60],
        ],
    );

    $manifest->markStepCompleted(TrackExecutionJobA::class);
    $manifest->advanceStep();

    try {
        (new PipelineStepJob($manifest))->handle();
    } catch (Throwable) {
        // expected under StopAndCompensate
    }

    // The CompensationStepJob wrapper should not inherit retry or timeout from the failing step.
    Bus::assertChained([
        function (CompensationStepJob $job): bool {
            return $job->compensationClass === CompensateJobA::class
                && $job->tries === 1
                && ($job->timeout ?? null) === null;
        },
    ]);
});
