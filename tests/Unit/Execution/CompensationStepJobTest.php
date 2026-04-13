<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
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
