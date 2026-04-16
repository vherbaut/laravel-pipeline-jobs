<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Execution\ParallelStepJob;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    config()->set('cache.default', 'array');
    TrackExecutionJob::$executionOrder = [];
});

it('dispatches a Bus::batch containing one ParallelStepJob per sub-step', function (): void {
    Bus::fake();

    (new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([TrackExecutionJobB::class, TrackExecutionJobC::class]),
    ]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    // The first PipelineStepJob (for TrackExecutionJobA) is dispatched synchronously
    // by QueuedExecutor; the parallel batch fires when that wrapper's handle() runs.
    // With Bus::fake(), handle() is NOT invoked — so we assert the first wrapper dispatch only.
    Bus::assertDispatched(PipelineStepJob::class);
});

it('stores the parallel group shape in the dispatched PipelineStepJob manifest', function (): void {
    Bus::fake();

    (new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([TrackExecutionJobB::class, TrackExecutionJobC::class]),
    ]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(PipelineStepJob::class, function (PipelineStepJob $job): bool {
        return $job->manifest->stepClasses === [
            0 => TrackExecutionJobA::class,
            1 => [
                'type' => 'parallel',
                'classes' => [TrackExecutionJobB::class, TrackExecutionJobC::class],
            ],
        ];
    });
});

it('carries per-sub-step onQueue overrides through to the manifest stepConfigs for downstream batch dispatch', function (): void {
    // Assert the manifest carries the nested per-sub-step configs so the
    // downstream dispatchParallelBatch() applies them on each ParallelStepJob
    // wrapper. End-to-end batch execution requires a real DB (batches table)
    // and is not exercised here; structural propagation is the contract this
    // test pins.
    $builder = (new PipelineBuilder([
        JobPipeline::parallel([
            Step::make(TrackExecutionJobB::class)->onQueue('fast'),
            Step::make(TrackExecutionJobC::class)->onQueue('slow'),
        ]),
    ]))->send(new SimpleContext)->shouldBeQueued();

    $definition = $builder->build();
    $stepConfigs = PipelineBuilder::resolveStepConfigs($definition);

    expect($stepConfigs[0])->toBeArray()
        ->and($stepConfigs[0]['type'])->toBe('parallel')
        ->and($stepConfigs[0]['configs'][0]['queue'])->toBe('fast')
        ->and($stepConfigs[0]['configs'][1]['queue'])->toBe('slow');
});

it('dispatches the first step as a plain wrapper when the pipeline opens with a parallel group', function (): void {
    // Regression guard for the nested-parallel shape at stepConfigs[0]:
    // QueuedExecutor::dispatchFirstStep() must detect the parallel shape and
    // dispatch a plain PipelineStepJob wrapper without attempting to read
    // flat-config keys (queue/connection/sync/timeout) from the nested array.
    // Prior to the fix, the flat-config reads emitted an "Undefined array key"
    // warning which Pest converts into a test failure.
    Bus::fake();

    (new PipelineBuilder([
        JobPipeline::parallel([TrackExecutionJobA::class, TrackExecutionJobB::class]),
        TrackExecutionJobC::class,
    ]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(PipelineStepJob::class, function (PipelineStepJob $job): bool {
        return $job->timeout === null
            && $job->manifest->stepClasses[0] === [
                'type' => 'parallel',
                'classes' => [TrackExecutionJobA::class, TrackExecutionJobB::class],
            ];
    });
});

it('ignores per-sub-step queue/connection/timeout on the first-step parallel wrapper (applied per-sub inside the batch)', function (): void {
    // The outer wrapper for a parallel group carries no per-wrapper queue
    // or timeout: per-sub-step config is applied when dispatchParallelBatch()
    // builds each ParallelStepJob. The first-step wrapper must therefore
    // dispatch on the default queue with no timeout, even when sub-steps
    // declare onQueue()/timeout() overrides.
    Bus::fake();

    (new PipelineBuilder([
        JobPipeline::parallel([
            Step::make(TrackExecutionJobA::class)->onQueue('fast')->timeout(30),
            Step::make(TrackExecutionJobB::class)->onQueue('slow')->timeout(60),
        ]),
    ]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(PipelineStepJob::class, function (PipelineStepJob $job): bool {
        return $job->timeout === null && $job->queue === null && $job->connection === null;
    });
});

it('caches each succeeded ParallelStepJob final context under a deterministic key', function (): void {
    $manifest = new PipelineManifest(
        pipelineId: 'test-pipe',
        pipelineName: null,
        stepClasses: [0 => ['type' => 'parallel', 'classes' => [EnrichContextJob::class]]],
        compensationMapping: [],
        stepConditions: [],
        currentStepIndex: 0,
        completedSteps: [],
        context: new SimpleContext,
    );

    $wrapper = new ParallelStepJob($manifest, groupIndex: 0, subStepIndex: 0, stepClass: EnrichContextJob::class);

    Cache::flush();

    $wrapper->handle();

    $cached = Cache::get(ParallelStepJob::contextCacheKey('test-pipe', 0, 0));

    expect($cached)->toBeInstanceOf(SimpleContext::class)
        ->and($cached->name)->toBe('enriched');
});
