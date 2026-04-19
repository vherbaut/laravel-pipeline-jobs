<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineConcurrencyLimitExceeded;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineException;

it('extends PipelineException so generic catches still match', function (): void {
    $exception = PipelineConcurrencyLimitExceeded::forKey('tenant:42', 5);

    expect($exception)->toBeInstanceOf(PipelineException::class);
});

it('formats the message with key and limit', function (): void {
    $exception = PipelineConcurrencyLimitExceeded::forKey('tenant:42', 5);

    expect($exception->getMessage())->toBe(
        'Pipeline concurrency limit exceeded for key "tenant:42" (limit: 5).',
    );
});

it('exposes resolved key and limit as readonly props', function (): void {
    $exception = PipelineConcurrencyLimitExceeded::forKey('tenant:42', 5);

    expect($exception->key)->toBe('tenant:42')
        ->and($exception->limit)->toBe(5);
});

it('constructor accepts an optional previous exception for chaining', function (): void {
    $previous = new RuntimeException('underlying issue');

    $exception = new PipelineConcurrencyLimitExceeded('msg', 'k', 5, $previous);

    expect($exception->getPrevious())->toBe($previous);
});
