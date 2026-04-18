<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
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
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ManifestSnapshotObserverJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

// The opt-in IntegrationTestCase harness (sqlite in-memory + `database` queue
// driver + inline `job_batches` / `jobs` / `failed_jobs` migrations) is wired
// to this directory by tests/Pest.php so individual test files do NOT
// register `uses(...)->in(...)` themselves — Pest forbids two parent classes
// for the same path, so the bootstrap lives in Pest.php.

// Integration tests intentionally cover the array API only: dual-API parity
// is already pinned at the builder layer by Story 8.1's unit/feature tests
// and both APIs converge at PipelineBuilder::build() before any fan-out
// dispatch. Repeating a database-backed drain for the fluent API would
// double the wall-clock cost with no coverage gain.

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
    ReadContextJob::$readCount = null;
    HookRecorder::reset();
    CompensateJobA::$executed = [];
    CompensateJobA::$onHandle = null;
    CompensateJobB::$executed = [];
    CompensateJobB::$onHandle = null;
    ManifestSnapshotObserverJob::reset();
    Cache::flush();
});

it('drains a queued pipeline with a parallel group end-to-end and merges both sub-step contributions', function (): void {
    $onSuccess = function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onSuccess';
        HookRecorder::$capturedContext = $ctx;
        HookRecorder::$contextWasCaptured = true;
    };

    $onComplete = function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onComplete';
    };

    (new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([EnrichContextJob::class, IncrementCountJob::class]),
        ReadContextJob::class,
    ]))
        ->send(new SimpleContext)
        ->onSuccess($onSuccess)
        ->onComplete($onComplete)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    // Only TrackExecutionJobA pushes to TrackExecutionJob::$executionOrder in
    // this pipeline (the parallel siblings mutate context; ReadContextJob
    // only records statics). Proves the array-api first-step wrapper ran.
    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);

    // The merged context flowing into ReadContextJob carries BOTH sub-step
    // contributions: name from EnrichContextJob and count from IncrementCountJob.
    expect(ReadContextJob::$readName)->toBe('enriched')
        ->and(ReadContextJob::$readCount)->toBe(1);

    // Terminal callbacks fire in onSuccess → onComplete order on the final
    // wrapper that handles ReadContextJob.
    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);

    // The batch row Laravel wrote during fan-out reports a clean finish:
    // all sub-step jobs succeeded, nothing pending, finished_at populated.
    $batch = DB::table('job_batches')
        ->where('name', 'LIKE', 'pipeline:%:parallel:1')
        ->first();

    expect($batch)->not->toBeNull();
    expect((int) $batch->pending_jobs)->toBe(0);
    expect((int) $batch->failed_jobs)->toBe(0);
    expect($batch->finished_at)->not->toBeNull();
});

it('dispatches the reversed compensation chain after a StopAndCompensate parallel failure', function (): void {
    // Snapshot compensation order by pushing each compensate job's class name
    // onto a shared log at handle-time. This sidesteps the per-class $executed
    // isolation (each class owns its own static) so the reverse-order
    // assertion is expressible as a single array comparison.
    CompensateJobA::$onHandle = function (): void {
        HookRecorder::$order[] = CompensateJobA::class;
    };

    CompensateJobB::$onHandle = function (): void {
        HookRecorder::$order[] = CompensateJobB::class;
    };

    $onFailure = function (?PipelineContext $ctx, Throwable $exception): void {
        HookRecorder::$fired[] = 'onFailure';
        HookRecorder::$capturedException = $exception;
    };

    $onComplete = function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onComplete';
    };

    Log::spy();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->parallel([
            Step::make(EnrichContextJob::class)->withCompensation(CompensateJobB::class),
            FailingJob::class,
        ])
        ->step(ReadContextJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->onFailure($onFailure)
        ->onComplete($onComplete)
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    // Batch-level catch callback registered by dispatchParallelBatch logs a
    // single observability warning when the FailingJob sibling trips.
    Log::shouldHaveReceived('warning')
        ->with('Pipeline parallel batch caught a sub-step failure', Mockery::any())
        ->atLeast()->once();

    // Reversed compensation chain: EnrichContextJob completed before the
    // failing sibling, so its compensation (CompensateJobB) runs first;
    // TrackExecutionJobA completed even earlier, so CompensateJobA runs
    // second. FailingJob is NOT in the mapping so its slot is skipped.
    expect(HookRecorder::$order)->toBe([CompensateJobB::class, CompensateJobA::class]);

    // Terminal callback order on the fan-in worker: onFailure then onComplete.
    expect(HookRecorder::$fired)->toBe(['onFailure', 'onComplete']);

    // ReadContextJob never ran: the pipeline terminated at the failing
    // group under StopAndCompensate.
    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class])
        ->and(ReadContextJob::$readName)->toBeNull();

    // The StepExecutionFailed synthesized by finalizeParallelBatch pins the
    // parallel-group position (= 1) into its message. This is the
    // observable proxy for $manifest->failedStepIndex === 1 — the manifest
    // itself is not exposed to the onFailure closure.
    expect(HookRecorder::$capturedException)->not->toBeNull()
        ->and(HookRecorder::$capturedException->getMessage())
        ->toContain('parallel group at position 1');
});

it('leaves currentStepIndex on the failing group under StopImmediately and does not dispatch the next step', function (): void {
    $onSuccess = function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onSuccess';
    };

    $onFailure = function (?PipelineContext $ctx, Throwable $exception): void {
        HookRecorder::$fired[] = 'onFailure';
        HookRecorder::$capturedException = $exception;
    };

    $onComplete = function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onComplete';
    };

    (new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([EnrichContextJob::class, FailingJob::class]),
        ReadContextJob::class,
    ]))
        ->send(new SimpleContext)
        ->onSuccess($onSuccess)
        ->onFailure($onFailure)
        ->onComplete($onComplete)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    // onFailure + onComplete fire exactly once each, in that order.
    // onSuccess never fires because the StopImmediately branch takes the
    // terminal-failure tail in finalizeParallelBatch.
    expect(HookRecorder::$fired)->toBe(['onFailure', 'onComplete']);

    // ReadContextJob was never dispatched: proves the pipeline did NOT
    // advance past the failing group's outer position (post-P10 invariant:
    // advanceStep lives on the success branch only).
    expect(ReadContextJob::$readName)->toBeNull()
        ->and(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);

    // Laravel's batch row counts the FailingJob sibling as failed. The
    // EnrichContextJob sibling succeeded and its signal lives in Cache, NOT
    // in failed_jobs — so the expected count is exactly 1.
    $batch = DB::table('job_batches')
        ->where('name', 'LIKE', 'pipeline:%:parallel:1')
        ->first();

    expect($batch)->not->toBeNull()
        ->and((int) $batch->failed_jobs)->toBe(1);
});

it('skips the failed sibling and continues to the next step under SkipAndContinue', function (): void {
    $onSuccess = function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onSuccess';
    };

    $onFailure = function (?PipelineContext $ctx, Throwable $exception): void {
        HookRecorder::$fired[] = 'onFailure';
    };

    $onComplete = function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onComplete';
    };

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->parallel([
            Step::make(EnrichContextJob::class)->withCompensation(CompensateJobB::class),
            FailingJob::class,
        ])
        ->step(ReadContextJob::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->onSuccess($onSuccess)
        ->onFailure($onFailure)
        ->onComplete($onComplete)
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    // The succeeded sibling's context wins the merge; the failed sibling's
    // slot is null in $finalContexts and is skipped by ParallelContextMerger.
    expect(ReadContextJob::$readName)->toBe('enriched');

    // Terminal tail fires onSuccess + onComplete: SkipAndContinue treats
    // sub-step failures as continuations, so the pipeline reaches its
    // success tail even though one sub-step actually failed.
    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);

    // No compensation dispatched under SkipAndContinue: the failed sibling
    // is forgotten (no saga rollback semantic).
    expect(CompensateJobB::$executed)->toBe([])
        ->and(CompensateJobA::$executed)->toBe([]);

    // The batch row still reports the failed sibling in failed_jobs even
    // though the pipeline tail fired onSuccess (batch.hasFailures() === true
    // is independent of the FailStrategy branching in finalizeParallelBatch).
    $batch = DB::table('job_batches')
        ->where('name', 'LIKE', 'pipeline:%:parallel:1')
        ->first();

    expect($batch)->not->toBeNull()
        ->and((int) $batch->failed_jobs)->toBe(1);
});

it('records sub-step completion via the succeeded cache signal when the pipeline carries no context', function (): void {
    $onSuccess = function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onSuccess';
        HookRecorder::$capturedContext = $ctx;
        HookRecorder::$contextWasCaptured = true;
    };

    $onComplete = function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onComplete';
    };

    // Pipeline has a terminal ManifestSnapshotObserverJob AFTER the parallel
    // group so the test can snapshot $manifest->completedSteps post-fan-in.
    // The AC's described topology is `[parallel(...)]` alone; we extend with
    // an observer step to capture manifest state because finalizeParallelBatch
    // does not pass the manifest to the onSuccess callback (only context).
    // The observer does not alter the context-less invariant: no `->send(...)`
    // means the manifest's context stays null throughout, and the observer
    // job's handle() tolerates a null context.
    (new PipelineBuilder([
        JobPipeline::parallel([TrackExecutionJobA::class, TrackExecutionJobB::class]),
        ManifestSnapshotObserverJob::class,
    ]))
        ->onSuccess($onSuccess)
        ->onComplete($onComplete)
        ->shouldBeQueued()
        ->run();

    $this->drainQueue();

    // Both sub-steps drove markStepCompleted off their succeeded-signal
    // cache entry (post-P2 patch): $manifest->completedSteps carries both
    // class names in declaration order even without a context entry.
    expect(ManifestSnapshotObserverJob::$observed)->toBeTrue()
        ->and(ManifestSnapshotObserverJob::$completedSteps)
        ->toBe([TrackExecutionJobA::class, TrackExecutionJobB::class]);

    // Terminal callbacks fire after the observer step runs, in success-tail order.
    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);

    // The onSuccess callback observed a null context: ParallelContextMerger::merge(null, [...])
    // returns null and no downstream step wrote into the manifest.
    expect(HookRecorder::$contextWasCaptured)->toBeTrue()
        ->and(HookRecorder::$capturedContext)->toBeNull();
});
