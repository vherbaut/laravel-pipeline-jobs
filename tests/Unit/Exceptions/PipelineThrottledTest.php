<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineException;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineThrottled;

it('extends PipelineException so generic catches still match', function (): void {
    $exception = PipelineThrottled::forKey('tenant:42', 30, 100, 60);

    expect($exception)->toBeInstanceOf(PipelineException::class);
});

it('formats the message with key, max, perSeconds, and retryAfter', function (): void {
    $exception = PipelineThrottled::forKey('tenant:42', 30, 100, 60);

    expect($exception->getMessage())->toBe(
        'Pipeline rate limit exceeded for key "tenant:42" (100 executions per 60 seconds); retry after 30 seconds.',
    );
});

it('exposes resolved key, retryAfter, max, and perSeconds as readonly props', function (): void {
    $exception = PipelineThrottled::forKey('tenant:42', 30, 100, 60);

    expect($exception->key)->toBe('tenant:42')
        ->and($exception->retryAfter)->toBe(30)
        ->and($exception->max)->toBe(100)
        ->and($exception->perSeconds)->toBe(60);
});

it('constructor accepts an optional previous exception for chaining', function (): void {
    $previous = new RuntimeException('underlying issue');

    $exception = new PipelineThrottled('msg', 'k', 10, 5, 60, $previous);

    expect($exception->getPrevious())->toBe($previous);
});
