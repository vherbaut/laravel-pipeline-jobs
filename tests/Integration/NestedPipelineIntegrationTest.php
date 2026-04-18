<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\HookRecorder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\IncrementCountJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
    ReadContextJob::$readCount = null;
    HookRecorder::reset();
    CompensateJobA::$executed = [];
    CompensateJobA::$onHandle = null;
    CompensateJobB::$executed = [];
    CompensateJobB::$onHandle = null;
    Cache::flush();
});

it('drains a queued pipeline with a nested group end-to-end and preserves inner context to outer steps', function (): void {
    $inner = JobPipeline::make([
        TrackExecutionJobA::class,
        EnrichContextJob::class,
    ]);

    (new PipelineBuilder([
        JobPipeline::nest($inner),
        ReadContextJob::class,
    ]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class])
        ->and(ReadContextJob::$readName)->toBe('enriched');
});

it('drains a queued nested pipeline under StopAndCompensate with reversed compensation chain spanning inner steps', function (): void {
    $inner = JobPipeline::make([
        Step::make(TrackExecutionJobA::class)->withCompensation(CompensateJobA::class),
        Step::make(TrackExecutionJobB::class)->withCompensation(CompensateJobB::class),
        FailingJob::class,
    ]);

    (new PipelineBuilder)
        ->nest($inner)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    // Reverse-order compensation: B then A (the outer flat completedSteps
    // list contains [TrackExecutionJobA, TrackExecutionJobB] before the
    // inner FailingJob threw).
    expect(CompensateJobB::$executed)->toBe([CompensateJobB::class])
        ->and(CompensateJobA::$executed)->toBe([CompensateJobA::class]);
});

it('drains a queued pipeline with parallel-inside-nested and merges context after the nested group', function (): void {
    // Pipeline shape: [TrackExecutionJobA, nest([EnrichContextJob, parallel([IncrementCountJob]), ReadContextJob])]
    // Verifies that the parallel sub-group inside the nested wrapper fires
    // its Bus::batch->finally() callback correctly under the real database
    // queue driver, then the next inner step (ReadContextJob) sees both the
    // nested-enrichment AND the parallel sub-step's merged contribution.
    $inner = JobPipeline::make([
        EnrichContextJob::class,
    ])->parallel([IncrementCountJob::class]);

    (new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::nest($inner),
        ReadContextJob::class,
    ]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class])
        ->and(ReadContextJob::$readName)->toBe('enriched')
        ->and(ReadContextJob::$readCount)->toBe(1);
});
