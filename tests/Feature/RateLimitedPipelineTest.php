<?php

declare(strict_types=1);

// Cross-test isolation: every test must reset the RateLimiter / Cache state
// it touches. Cache::flush() in beforeEach handles concurrency-counter leaks;
// RateLimiter::clear() per resolved key handles rate-limit-counter leaks.

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineThrottled;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    Cache::flush();
    foreach (['rate-key', 'tenant:42', 'compose-key'] as $key) {
        RateLimiter::clear($key);
    }
});

// -----------------------------------------------------------------------------
// Sync mode — admission, rejection, and zero side effects on rejection
// -----------------------------------------------------------------------------

it('sync: admits pipelines within the rate limit', function (Closure $builderFactory): void {
    $builderFactory()->run();
    $builderFactory()->run();
    $builderFactory()->run();

    expect(TrackExecutionJob::$executionOrder)->toHaveCount(3);
})->with([
    'array API' => fn () => (new PipelineBuilder([TrackExecutionJobA::class]))->rateLimit('rate-key', 3, 60),
    'fluent API' => fn () => (new PipelineBuilder)->step(TrackExecutionJobA::class)->rateLimit('rate-key', 3, 60),
]);

it('sync: throws PipelineThrottled when rate limit exceeded', function (Closure $builderFactory): void {
    $builderFactory()->run();
    $builderFactory()->run();

    expect(static fn () => $builderFactory()->run())
        ->toThrow(PipelineThrottled::class);
})->with([
    'array API' => fn () => (new PipelineBuilder([TrackExecutionJobA::class]))->rateLimit('rate-key', 2, 60),
    'fluent API' => fn () => (new PipelineBuilder)->step(TrackExecutionJobA::class)->rateLimit('rate-key', 2, 60),
]);

it('sync: throttled run does NOT execute any step (no execution order entries from the rejected attempt)', function (): void {
    $factory = fn () => JobPipeline::make([TrackExecutionJobA::class])->rateLimit('rate-key', 1, 60);

    $factory()->run();
    expect(TrackExecutionJob::$executionOrder)->toHaveCount(1);

    try {
        $factory()->run();
    } catch (PipelineThrottled) {
        // expected
    }

    expect(TrackExecutionJob::$executionOrder)->toHaveCount(1);
});

it('sync: throttled run does NOT dispatch lifecycle events (Event::fake spy assertion)', function (): void {
    Event::fake([PipelineStepCompleted::class, PipelineStepFailed::class, PipelineCompleted::class]);
    RateLimiter::hit('rate-key', 60); // pre-seed quota

    $builder = JobPipeline::make([TrackExecutionJobA::class])
        ->dispatchEvents()
        ->rateLimit('rate-key', 1, 60);

    expect(static fn () => $builder->run())->toThrow(PipelineThrottled::class);

    Event::assertNotDispatched(PipelineStepCompleted::class);
    Event::assertNotDispatched(PipelineCompleted::class);
});

it('sync: PipelineThrottled exposes a populated retryAfter from RateLimiter::availableIn', function (): void {
    RateLimiter::hit('rate-key', 60);
    RateLimiter::hit('rate-key', 60);
    $factory = fn () => JobPipeline::make([TrackExecutionJobA::class])->rateLimit('rate-key', 2, 60);

    try {
        $factory()->run();
        $this->fail('expected PipelineThrottled');
    } catch (PipelineThrottled $exception) {
        expect($exception->key)->toBe('rate-key')
            ->and($exception->max)->toBe(2)
            ->and($exception->perSeconds)->toBe(60)
            ->and($exception->retryAfter)->toBeGreaterThanOrEqual(0)
            ->and($exception->retryAfter)->toBeLessThanOrEqual(60);
    }
});

it('sync: rate-limit gate runs BEFORE concurrency gate (rejection does not consume a slot)', function (): void {
    RateLimiter::hit('compose-key', 60); // exhaust rate-limit (max=1)
    Cache::add('pipeline:concurrent:compose-key', 0, 3600);
    $countBefore = (int) Cache::get('pipeline:concurrent:compose-key');

    $factory = fn () => JobPipeline::make([TrackExecutionJobA::class])
        ->rateLimit('compose-key', 1, 60)
        ->maxConcurrent('compose-key', 5);

    expect(static fn () => $factory()->run())->toThrow(PipelineThrottled::class);
    // concurrency counter unchanged (no acquire ever happened)
    expect((int) Cache::get('pipeline:concurrent:compose-key'))->toBe($countBefore);
});

// -----------------------------------------------------------------------------
// Queued mode — gate at dispatch time
// -----------------------------------------------------------------------------

it('queued: rate-limit gates at dispatch time (under Bus::fake)', function (): void {
    Bus::fake();

    $factory = fn () => JobPipeline::make([TrackExecutionJobA::class])
        ->rateLimit('rate-key', 2, 60)
        ->shouldBeQueued();

    $factory()->run();
    $factory()->run();

    expect(static fn () => $factory()->run())->toThrow(PipelineThrottled::class);

    Bus::assertDispatchedTimes(PipelineStepJob::class, 2);
});

// -----------------------------------------------------------------------------
// Story 9.3 AC #15 — Zero-overhead: an unthrottled pipeline performs no
// RateLimiter / Cache facade calls at all.
// -----------------------------------------------------------------------------

it('unthrottled pipeline performs no RateLimiter / Cache facade calls (zero-overhead)', function (): void {
    RateLimiter::spy();
    Cache::spy();

    JobPipeline::make([TrackExecutionJobA::class])->run();

    RateLimiter::shouldNotHaveReceived('hit');
    RateLimiter::shouldNotHaveReceived('tooManyAttempts');
    RateLimiter::shouldNotHaveReceived('availableIn');
    Cache::shouldNotHaveReceived('add');
    Cache::shouldNotHaveReceived('increment');
    Cache::shouldNotHaveReceived('decrement');
});
