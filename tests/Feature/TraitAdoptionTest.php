<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
    CompensateJobA::$executed = [];
    CompensateJobA::$observedName = null;
});

// --- AC #1 / #6: forward step sees mutations from earlier trait-using step ---

it('exposes mutated context to a later trait-using step via pipelineContext()', function (Closure $builderFactory): void {
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

// --- AC #2 / FR42: standalone dispatch path remains unaffected ---

it('does not dispatch a pipeline when a trait-using job is handed to Bus::dispatch', function (): void {
    Bus::fake();

    Bus::dispatch(new EnrichContextJob);

    Bus::assertDispatched(EnrichContextJob::class);
});

it('runs a trait-using job synchronously with no pipeline context available', function (): void {
    $context = new SimpleContext;
    $context->name = 'will-not-be-touched';

    (new EnrichContextJob)->handle();

    expect($context->name)->toBe('will-not-be-touched');
});

it('reports hasPipelineContext() as false immediately after instantiation', function (): void {
    $job = new EnrichContextJob;

    expect($job->hasPipelineContext())->toBeFalse()
        ->and($job->pipelineContext())->toBeNull();
});

// --- AC #6: trait-using job coexists with non-trait surface unchanged ---

it('lets a trait-using job run alongside a bare step in the same pipeline', function (): void {
    $context = new SimpleContext;

    Pipeline::make()
        ->step(TrackExecutionJobA::class)
        ->step(EnrichContextJob::class)
        ->step(ReadContextJob::class)
        ->step(TrackExecutionJobB::class)
        ->send($context)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ])
        ->and(ReadContextJob::$readName)->toBe('enriched');
});

// --- AC #7: Pipeline::fake()->recording() observes the trait injection ---

it('records context mutations from a trait-using step under Pipeline::fake()->recording()', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make([EnrichContextJob::class])
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertContextHas('name', 'enriched', pipelineIndex: 0);
});

// --- AC #9: compensation job using the trait sees prior mutations ---

it('compensation job sees forward-step mutations through pipelineContext()', function (): void {
    Pipeline::fake()->recording();

    Pipeline::make()
        ->step(EnrichContextJob::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run();

    Pipeline::assertCompensationWasTriggered();
    Pipeline::assertCompensationRan(CompensateJobA::class);

    expect(CompensateJobA::$observedName)->toBe('enriched');
});
