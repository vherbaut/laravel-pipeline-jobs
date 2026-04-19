<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\RateLimitPolicy;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('stores the literal string key, max, and perSeconds verbatim', function (): void {
    $policy = new RateLimitPolicy('tenant:42', 100, 60);

    expect($policy->key)->toBe('tenant:42')
        ->and($policy->max)->toBe(100)
        ->and($policy->perSeconds)->toBe(60);
});

it('stores a closure key without invoking it at construction time', function (): void {
    $resolver = static fn (?PipelineContext $ctx): string => 'tenant:resolved';

    $policy = new RateLimitPolicy($resolver, 5, 30);

    expect($policy->key)->toBe($resolver)
        ->and($policy->max)->toBe(5)
        ->and($policy->perSeconds)->toBe(30);
});

it('resolveKey returns the literal string when key is a string', function (): void {
    $policy = new RateLimitPolicy('static-key', 1, 1);

    expect($policy->resolveKey(null))->toBe('static-key');
});

it('resolveKey invokes the closure with the live context and returns its string', function (): void {
    $context = new SimpleContext;
    $context->name = 'alice';

    $policy = new RateLimitPolicy(static fn (?PipelineContext $ctx): string => 'user:'.($ctx->name ?? 'anon'), 1, 1);

    expect($policy->resolveKey($context))->toBe('user:alice');
});

it('resolveKey invokes the closure with null context when admission has no context', function (): void {
    $policy = new RateLimitPolicy(static fn (?PipelineContext $ctx): string => $ctx === null ? 'anonymous' : 'with-context', 1, 1);

    expect($policy->resolveKey(null))->toBe('anonymous');
});

it('resolveKey throws InvalidPipelineDefinition when closure returns non-string', function (): void {
    $policy = new RateLimitPolicy(static fn (?PipelineContext $ctx): mixed => 12345, 1, 1);

    expect(static fn () => $policy->resolveKey(null))
        ->toThrow(InvalidPipelineDefinition::class, 'rateLimit key closure must return a non-empty string, got int.');
});

it('resolveKey throws InvalidPipelineDefinition when closure returns empty string', function (): void {
    $policy = new RateLimitPolicy(static fn (?PipelineContext $ctx): string => '', 1, 1);

    expect(static fn () => $policy->resolveKey(null))
        ->toThrow(InvalidPipelineDefinition::class, 'rateLimit key closure must return a non-empty string, got string.');
});

it('resolveKey throws InvalidPipelineDefinition when closure returns whitespace-only string', function (): void {
    $policy = new RateLimitPolicy(static fn (?PipelineContext $ctx): string => "   \t\n", 1, 1);

    expect(static fn () => $policy->resolveKey(null))
        ->toThrow(InvalidPipelineDefinition::class);
});
