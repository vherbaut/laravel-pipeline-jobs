<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\ConcurrencyPolicy;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('stores the literal string key and limit verbatim', function (): void {
    $policy = new ConcurrencyPolicy('tenant:42', 5);

    expect($policy->key)->toBe('tenant:42')
        ->and($policy->limit)->toBe(5);
});

it('stores a closure key without invoking it at construction time', function (): void {
    $resolver = static fn (?PipelineContext $ctx): string => 'tenant:resolved';

    $policy = new ConcurrencyPolicy($resolver, 3);

    expect($policy->key)->toBe($resolver)
        ->and($policy->limit)->toBe(3);
});

it('resolveKey returns the literal string when key is a string', function (): void {
    $policy = new ConcurrencyPolicy('static-key', 1);

    expect($policy->resolveKey(null))->toBe('static-key');
});

it('resolveKey invokes the closure with the live context and returns its string', function (): void {
    $context = new SimpleContext;
    $context->name = 'bob';

    $policy = new ConcurrencyPolicy(static fn (?PipelineContext $ctx): string => 'user:'.($ctx->name ?? 'anon'), 2);

    expect($policy->resolveKey($context))->toBe('user:bob');
});

it('resolveKey throws InvalidPipelineDefinition when closure returns non-string', function (): void {
    $policy = new ConcurrencyPolicy(static fn (?PipelineContext $ctx): mixed => null, 1);

    expect(static fn () => $policy->resolveKey(null))
        ->toThrow(InvalidPipelineDefinition::class, 'maxConcurrent key closure must return a non-empty string, got null.');
});

it('resolveKey throws InvalidPipelineDefinition when closure returns empty string', function (): void {
    $policy = new ConcurrencyPolicy(static fn (?PipelineContext $ctx): string => '', 1);

    expect(static fn () => $policy->resolveKey(null))
        ->toThrow(InvalidPipelineDefinition::class, 'maxConcurrent key closure must return a non-empty string, got string.');
});
