<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];
    CompensateJobC::$executed = [];
    TrackExecutionJob::$executionOrder = [];
});

// --- 11.1: Full compensation flow: 3 steps with compensation, middle step fails ---

it('runs compensation in reverse order when middle step fails in a 3-step pipeline', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)->compensateWith(CompensateJobC::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->send(new SimpleContext)
        ->run();

    // Only TrackExecutionJobA completed before failure
    Pipeline::assertStepExecuted(TrackExecutionJobA::class);
    Pipeline::assertStepNotExecuted(TrackExecutionJobB::class);

    // FailingJob failed, so its compensation (CompensateJobC) does NOT run
    // Only CompensateJobA runs (for the completed TrackExecutionJobA)
    Pipeline::assertCompensationWasTriggered();
    Pipeline::assertCompensationRan(CompensateJobA::class);
    Pipeline::assertCompensationNotRan(CompensateJobC::class);
    Pipeline::assertCompensationNotRan(CompensateJobB::class);
    Pipeline::assertCompensationExecutedInOrder([CompensateJobA::class]);
});

// --- 11.2: Pipeline with no compensation defined ---

it('assertCompensationNotTriggered passes when no compensation is defined and step fails', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobC::class])
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertCompensationNotTriggered();
});

// --- 11.3: Partial compensation: only some steps have compensation defined ---

it('only compensates steps that have compensation defined', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)  // no compensation
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertCompensationWasTriggered();

    // CompensateJobA ran (for TrackExecutionJobA which completed)
    Pipeline::assertCompensationRan(CompensateJobA::class);

    // No compensation for TrackExecutionJobB (none defined)
    Pipeline::assertCompensationNotRan(CompensateJobB::class);

    // Reverse order: only CompensateJobA
    Pipeline::assertCompensationExecutedInOrder([CompensateJobA::class]);
});

// --- 11.4: Both API entry points: fluent step()->compensateWith() ---

it('works with the fluent step()->compensateWith() API', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)
        ->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)
        ->compensateWith(CompensateJobB::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertCompensationWasTriggered();
    Pipeline::assertCompensationExecutedInOrder([
        CompensateJobB::class,
        CompensateJobA::class,
    ]);
});

// --- 11.5: Compensation + step assertions combined on same pipeline ---

it('compensation and step assertions work together on same recorded pipeline', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    // Forward step assertions
    Pipeline::assertStepExecuted(TrackExecutionJobA::class);
    Pipeline::assertStepExecuted(TrackExecutionJobB::class);
    Pipeline::assertStepNotExecuted(FailingJob::class);
    Pipeline::assertStepsExecutedInOrder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]);

    // Compensation assertions
    Pipeline::assertCompensationWasTriggered();
    Pipeline::assertCompensationRan(CompensateJobB::class);
    Pipeline::assertCompensationRan(CompensateJobA::class);
    Pipeline::assertCompensationExecutedInOrder([
        CompensateJobB::class,
        CompensateJobA::class,
    ]);
});

// --- 11.6: Compensation + context assertions combined ---

it('compensation and context assertions work together (context state at failure point)', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(EnrichContextJob::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->send(new SimpleContext)
        ->run();

    // Context was enriched by the first step before failure
    Pipeline::assertContextHas('name', 'enriched');

    // Compensation ran for the completed step
    Pipeline::assertCompensationWasTriggered();
    Pipeline::assertCompensationRan(CompensateJobA::class);
});
