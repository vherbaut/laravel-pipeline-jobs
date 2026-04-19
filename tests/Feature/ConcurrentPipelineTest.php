<?php

declare(strict_types=1);

// Cross-test isolation: concurrency tests seed and inspect Cache counters.
// Each test resets via Cache::flush() in beforeEach. Production users MUST
// configure Redis / Memcached / Database for cross-worker atomicity; tests
// run on the array store which is single-process and deterministic.

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\ContextSerializationFailed;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineConcurrencyLimitExceeded;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\NonSerializableContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
    Cache::flush();
});

// -----------------------------------------------------------------------------
// Sync mode — admission, rejection, and slot release on every terminal branch
// -----------------------------------------------------------------------------

it('sync: admits pipelines up to the configured limit (counter returns to zero on success)', function (Closure $builderFactory): void {
    $builderFactory()->run();
    $builderFactory()->run();

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
})->with([
    'array API' => fn () => (new PipelineBuilder([TrackExecutionJobA::class]))->maxConcurrent('slot', 2),
    'fluent API' => fn () => (new PipelineBuilder)->step(TrackExecutionJobA::class)->maxConcurrent('slot', 2),
]);

it('sync: throws PipelineConcurrencyLimitExceeded when the simulated slot count is already at the limit', function (Closure $builderFactory): void {
    Cache::add('pipeline:concurrent:slot', 2, 3600);

    expect(static fn () => $builderFactory()->run())->toThrow(PipelineConcurrencyLimitExceeded::class);
    // counter rolled back to the seeded value on rejection
    expect(Cache::get('pipeline:concurrent:slot'))->toBe(2);
})->with([
    'array API' => fn () => (new PipelineBuilder([TrackExecutionJobA::class]))->maxConcurrent('slot', 2),
    'fluent API' => fn () => (new PipelineBuilder)->step(TrackExecutionJobA::class)->maxConcurrent('slot', 2),
]);

it('sync: releases the slot on success (counter is zero after run)', function (): void {
    JobPipeline::make([TrackExecutionJobA::class])->maxConcurrent('slot', 5)->run();

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

it('sync: releases the slot on StepExecutionFailed rethrow (failure tail)', function (): void {
    $builder = JobPipeline::make([FailingJob::class])->maxConcurrent('slot', 5);

    try {
        $builder->run();
    } catch (StepExecutionFailed) {
        // expected
    }

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

it('sync: releases the slot on StopAndCompensate rethrow after compensation', function (): void {
    $builder = JobPipeline::make([
        TrackExecutionJobA::class,
        FailingJob::class,
    ])
        ->onFailure(FailStrategy::StopAndCompensate)
        ->maxConcurrent('slot', 5);

    try {
        $builder->run();
    } catch (StepExecutionFailed) {
        // expected
    }

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

it('sync: releases the slot on SkipAndContinue success tail', function (): void {
    JobPipeline::make([
        TrackExecutionJobA::class,
        FailingJob::class,
    ])
        ->onFailure(FailStrategy::SkipAndContinue)
        ->maxConcurrent('slot', 5)
        ->run();

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

it('sync: releases the slot when the return() callback throws', function (): void {
    $builder = JobPipeline::make([TrackExecutionJobA::class])
        ->send(new SimpleContext)
        ->return(static fn () => throw new RuntimeException('boom'))
        ->maxConcurrent('slot', 5);

    try {
        $builder->run();
    } catch (RuntimeException) {
        // expected: return() throws propagate verbatim, slot must release in finally
    }

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

// -----------------------------------------------------------------------------
// Queued mode — gate at dispatch time + terminal release on success/failure tails
// -----------------------------------------------------------------------------

it('queued (sync driver): increments slot at dispatch and decrements at terminal completion', function (): void {
    config()->set('queue.default', 'sync');

    JobPipeline::make([TrackExecutionJobA::class])
        ->maxConcurrent('slot', 5)
        ->shouldBeQueued()
        ->run();

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

it('queued (sync driver): releases the slot on terminal failure tail', function (): void {
    config()->set('queue.default', 'sync');

    $builder = JobPipeline::make([FailingJob::class])
        ->maxConcurrent('slot', 5)
        ->shouldBeQueued();

    try {
        $builder->run();
    } catch (Throwable) {
        // expected
    }

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

// Story 9.3 Task 6.4 — missing queued coverage (added via code-review patch P3).

it('queued (sync driver): releases the slot on StopAndCompensate failure tail after compensation dispatch', function (): void {
    config()->set('queue.default', 'sync');

    $builder = (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->maxConcurrent('slot', 5)
        ->shouldBeQueued();

    try {
        $builder->run();
    } catch (Throwable) {
        // expected: StopAndCompensate rethrows the underlying step failure
    }

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

it('queued (sync driver): releases the slot on SkipAndContinue success tail', function (): void {
    config()->set('queue.default', 'sync');

    JobPipeline::make([
        TrackExecutionJobA::class,
        FailingJob::class,
    ])
        ->onFailure(FailStrategy::SkipAndContinue)
        ->maxConcurrent('slot', 5)
        ->shouldBeQueued()
        ->run();

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

it('queued: releases the slot inline when ContextSerializationFailed is thrown at dispatch time', function (): void {
    Bus::fake();

    $context = new NonSerializableContext;
    $context->callback = static fn (): string => 'not-serializable';

    $builder = JobPipeline::make([TrackExecutionJobA::class])
        ->send($context)
        ->maxConcurrent('slot', 5)
        ->shouldBeQueued();

    try {
        $builder->run();
    } catch (ContextSerializationFailed) {
        // expected: pipeline never started, slot must release inline
    }

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
    Bus::assertNotDispatched(PipelineStepJob::class);
});

// Story 9.3 code-review P1 regression — queued failure tail with throwing
// pipeline-level callback still releases the slot via the new finally guard.

it('queued (sync driver): releases the slot even when onFailureCallback throws', function (): void {
    config()->set('queue.default', 'sync');

    $builder = JobPipeline::make([FailingJob::class])
        ->onFailure(FailStrategy::StopImmediately)
        ->onFailure(static fn () => throw new RuntimeException('onFailure blew up'))
        ->maxConcurrent('slot', 5)
        ->shouldBeQueued();

    try {
        $builder->run();
    } catch (Throwable) {
        // expected: StepExecutionFailed::forCallbackFailure wraps the callback throw
    }

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});

it('queued (sync driver): releases the slot even when onCompleteCallback throws on success tail', function (): void {
    config()->set('queue.default', 'sync');

    $builder = JobPipeline::make([TrackExecutionJobA::class])
        ->onComplete(static fn () => throw new RuntimeException('onComplete blew up'))
        ->maxConcurrent('slot', 5)
        ->shouldBeQueued();

    try {
        $builder->run();
    } catch (Throwable) {
        // expected: onComplete throw propagates from the success tail
    }

    expect(Cache::get('pipeline:concurrent:slot'))->toBe(0);
});
