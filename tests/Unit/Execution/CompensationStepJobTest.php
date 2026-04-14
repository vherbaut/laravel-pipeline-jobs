<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;
use Vherbaut\LaravelPipelineJobs\Execution\CompensationStepJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ContractBasedCompensation;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingCompensationJob;

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
