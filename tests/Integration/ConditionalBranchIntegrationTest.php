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
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

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

it('drains a queued pipeline with a conditional branch end-to-end and only the selected branch executes', function (): void {
    $context = new SimpleContext;
    $context->name = 'left';

    (new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(
            fn (SimpleContext $ctx) => $ctx->name,
            [
                'left' => EnrichContextJob::class,
                'right' => FailingJob::class,
            ],
        ),
        ReadContextJob::class,
    ]))
        ->send($context)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class])
        ->and(ReadContextJob::$readName)->toBe('enriched');
});

it('drains a queued conditional branch under StopAndCompensate and compensates only prior completed steps', function (): void {
    $context = new SimpleContext;
    $context->name = 'bad';

    (new PipelineBuilder([
        Step::make(TrackExecutionJobA::class)->withCompensation(CompensateJobA::class),
        Step::branch(
            fn (SimpleContext $ctx) => $ctx->name,
            [
                'good' => Step::make(TrackExecutionJobB::class)->withCompensation(CompensateJobB::class),
                'bad' => FailingJob::class,
            ],
        ),
    ]))
        ->send($context)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    expect(CompensateJobA::$executed)->toBe([CompensateJobA::class])
        ->and(CompensateJobB::$executed)->toBe([]);
});

it('drains a queued conditional branch embedded inside a nested pipeline (branch-inside-nested AC #13)', function (): void {
    $context = new SimpleContext;
    $context->name = 'left';

    $inner = JobPipeline::make([
        TrackExecutionJobA::class,
        Step::branch(
            fn (SimpleContext $ctx) => $ctx->name,
            [
                'left' => TrackExecutionJobB::class,
                'right' => FailingJob::class,
            ],
        ),
        TrackExecutionJobC::class,
    ]);

    (new PipelineBuilder([
        JobPipeline::nest($inner),
        TrackExecutionJobA::class,
    ]))
        ->send($context)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
        TrackExecutionJobA::class,
    ]);
});

it('drains a queued conditional branch whose selected value is a nested pipeline and runs inner steps via the cursor', function (): void {
    $context = new SimpleContext;
    $context->name = 'right';

    (new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::branch(
            fn (SimpleContext $ctx) => $ctx->name,
            [
                'left' => TrackExecutionJobB::class,
                'right' => JobPipeline::make([TrackExecutionJobB::class, TrackExecutionJobC::class]),
            ],
        ),
        TrackExecutionJobA::class,
    ]))
        ->send($context)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
        TrackExecutionJobA::class,
    ]);
});
