<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];
    CompensateJobC::$executed = [];
});

// --- 10.1: assertCompensationWasTriggered passes when compensation ran ---

it('assertCompensationWasTriggered passes when compensation ran', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertCompensationWasTriggered();
});

// --- 10.2: assertCompensationWasTriggered fails when no compensation triggered ---

it('assertCompensationWasTriggered fails when no compensation triggered', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertCompensationWasTriggered())
        ->toThrow(ExpectationFailedException::class, 'compensation');
});

// --- 10.3: assertCompensationNotTriggered passes when no failure ---

it('assertCompensationNotTriggered passes when no failure', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertCompensationNotTriggered();
});

// --- 10.4: assertCompensationNotTriggered fails when compensation triggered ---

it('assertCompensationNotTriggered fails when compensation triggered', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertCompensationNotTriggered())
        ->toThrow(ExpectationFailedException::class, 'compensation');
});

// --- 10.5: assertCompensationRan passes for executed compensation job ---

it('assertCompensationRan passes for executed compensation job', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertCompensationRan(CompensateJobA::class);
    Pipeline::assertCompensationRan(CompensateJobB::class);
});

// --- 10.6: assertCompensationRan fails for non-executed compensation job ---

it('assertCompensationRan fails for non-executed compensation job', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertCompensationRan(CompensateJobC::class))
        ->toThrow(ExpectationFailedException::class, 'CompensateJobC');
});

// --- 10.7: assertCompensationNotRan passes for post-failure step ---

it('assertCompensationNotRan passes for compensation job that did not run', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertCompensationNotRan(CompensateJobC::class);
});

// --- 10.8: assertCompensationNotRan fails for executed compensation job ---

it('assertCompensationNotRan fails for executed compensation job', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertCompensationNotRan(CompensateJobA::class))
        ->toThrow(ExpectationFailedException::class, 'CompensateJobA');
});

// --- 10.9: assertCompensationExecutedInOrder passes with correct reverse order ---

it('assertCompensationExecutedInOrder passes with correct reverse order', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertCompensationExecutedInOrder([
        CompensateJobB::class,
        CompensateJobA::class,
    ]);
});

// --- 10.10: assertCompensationExecutedInOrder fails with wrong order ---

it('assertCompensationExecutedInOrder fails with wrong order', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    expect(fn () => Pipeline::assertCompensationExecutedInOrder([
        CompensateJobA::class,
        CompensateJobB::class,
    ]))->toThrow(ExpectationFailedException::class);
});

// --- 10.11: compensation assertions require recording mode ---

it('compensation assertions require recording mode', function (): void {
    Pipeline::fake();

    Pipeline::make([FakeJobA::class])->run();

    expect(fn () => Pipeline::assertCompensationWasTriggered())
        ->toThrow(ExpectationFailedException::class, 'recording()');
});

// --- 10.12: compensateWith() on builder without prior step throws ---

it('compensateWith() on builder without prior step throws', function (): void {
    Pipeline::fake();

    expect(fn () => Pipeline::make()->compensateWith(CompensateJobA::class))
        ->toThrow(InvalidPipelineDefinition::class);
});

// --- 10.13: step + compensation assertions work together on same pipeline ---

it('step and compensation assertions work together on same pipeline', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertStepExecuted(TrackExecutionJobA::class);
    Pipeline::assertStepExecuted(TrackExecutionJobB::class);
    Pipeline::assertStepNotExecuted(FailingJob::class);

    Pipeline::assertCompensationWasTriggered();
    Pipeline::assertCompensationExecutedInOrder([
        CompensateJobB::class,
        CompensateJobA::class,
    ]);
});
