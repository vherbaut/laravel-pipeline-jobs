<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineThrottled;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\PipelineRateLimiter;
use Vherbaut\LaravelPipelineJobs\RateLimitPolicy;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

beforeEach(function (): void {
    RateLimiter::clear('test-key');
    RateLimiter::clear('user:alice');
});

it('gate is a no-op when policy is null (zero-overhead fast path)', function (): void {
    RateLimiter::spy();

    PipelineRateLimiter::gate(null, null);

    RateLimiter::shouldNotHaveReceived('tooManyAttempts');
    RateLimiter::shouldNotHaveReceived('hit');
    RateLimiter::shouldNotHaveReceived('availableIn');
});

it('gate consumes one token via RateLimiter::hit on admission', function (): void {
    $policy = new RateLimitPolicy('test-key', 3, 60);

    PipelineRateLimiter::gate($policy, null);

    expect(RateLimiter::attempts('test-key'))->toBe(1);
});

it('gate admits up to max attempts within the window', function (): void {
    $policy = new RateLimitPolicy('test-key', 3, 60);

    PipelineRateLimiter::gate($policy, null);
    PipelineRateLimiter::gate($policy, null);
    PipelineRateLimiter::gate($policy, null);

    expect(RateLimiter::attempts('test-key'))->toBe(3);
});

it('gate throws PipelineThrottled when quota exceeded with retryAfter populated', function (): void {
    $policy = new RateLimitPolicy('test-key', 2, 60);

    PipelineRateLimiter::gate($policy, null);
    PipelineRateLimiter::gate($policy, null);

    try {
        PipelineRateLimiter::gate($policy, null);
        $this->fail('expected PipelineThrottled');
    } catch (PipelineThrottled $exception) {
        expect($exception->key)->toBe('test-key')
            ->and($exception->max)->toBe(2)
            ->and($exception->perSeconds)->toBe(60)
            ->and($exception->retryAfter)->toBeGreaterThanOrEqual(0)
            ->and($exception->retryAfter)->toBeLessThanOrEqual(60);
    }
});

it('gate resolves the closure key against the live PipelineContext', function (): void {
    $context = new SimpleContext;
    $context->name = 'alice';

    $policy = new RateLimitPolicy(static fn (?PipelineContext $ctx): string => 'user:'.($ctx->name ?? 'anon'), 2, 60);

    PipelineRateLimiter::gate($policy, $context);

    expect(RateLimiter::attempts('user:alice'))->toBe(1);
});
