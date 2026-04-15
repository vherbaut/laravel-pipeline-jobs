<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\HookRecorder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    HookRecorder::reset();
});

it('parity (sync): make and dispatch produce identical execution order for the same config', function (): void {
    $ctx = new SimpleContext;

    Pipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->send($ctx)
        ->onFailure(FailStrategy::StopImmediately)
        ->beforeEach(function ($step, $c): void {
            HookRecorder::$beforeEach[] = $step->jobClass;
        })
        ->run();

    $makeOrder = TrackExecutionJob::$executionOrder;
    $makeHooks = HookRecorder::$beforeEach;

    TrackExecutionJob::$executionOrder = [];
    HookRecorder::reset();

    Pipeline::dispatch([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->send($ctx)
        ->onFailure(FailStrategy::StopImmediately)
        ->beforeEach(function ($step, $c): void {
            HookRecorder::$beforeEach[] = $step->jobClass;
        });

    expect(TrackExecutionJob::$executionOrder)->toBe($makeOrder)
        ->and(HookRecorder::$beforeEach)->toBe($makeHooks);
});

it('parity (queued): make and dispatch produce identical PipelineStepJob manifest (excluding pipelineId)', function (): void {
    Bus::fake();

    $ctx = new SimpleContext;
    $ctx->name = 'parity-ctx';

    Pipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->send($ctx)
        ->onQueue('shared-queue')
        ->retry(1)
        ->backoff(2)
        ->timeout(15)
        ->shouldBeQueued()
        ->run();

    /** @var PipelineStepJob $jobFromMake */
    $jobFromMake = Bus::dispatched(PipelineStepJob::class)->first();

    Bus::fake();

    Pipeline::dispatch([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->send($ctx)
        ->onQueue('shared-queue')
        ->retry(1)
        ->backoff(2)
        ->timeout(15)
        ->shouldBeQueued();

    /** @var PipelineStepJob $jobFromDispatch */
    $jobFromDispatch = Bus::dispatched(PipelineStepJob::class)->first();

    $strip = static fn (PipelineManifest $m): array => array_diff_key(
        (array) $m,
        ['pipelineId' => null],
    );

    expect($strip($jobFromMake->manifest))->toBe($strip($jobFromDispatch->manifest));
    expect($jobFromMake->queue)->toBe($jobFromDispatch->queue)
        ->and($jobFromMake->timeout)->toBe($jobFromDispatch->timeout);
});

it('parity: return() is NOT proxied on the dispatch wrapper (regression guard for AC #6)', function (): void {
    Pipeline::fake();

    expect(fn () => Pipeline::dispatch([TrackExecutionJobA::class])->return(fn () => null))
        ->toThrow(Error::class, 'return');
});
