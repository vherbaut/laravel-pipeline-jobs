<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

it('constructs from explicit values and exposes all three readonly properties', function (): void {
    $exception = new RuntimeException('boom');

    $failure = new FailureContext('App\\Jobs\\Foo', 2, $exception);

    expect($failure->failedStepClass)->toBe('App\\Jobs\\Foo')
        ->and($failure->failedStepIndex)->toBe(2)
        ->and($failure->exception)->toBe($exception);

    expect(fn () => $failure->failedStepClass = 'X')->toThrow(Error::class);
});

it('builds a FailureContext from a manifest carrying a failure', function (): void {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Foo']);
    $exception = new RuntimeException('boom');

    $manifest->failedStepClass = 'App\\Jobs\\Foo';
    $manifest->failedStepIndex = 3;
    $manifest->failureException = $exception;

    $failure = FailureContext::fromManifest($manifest);

    expect($failure)->not->toBeNull()
        ->and($failure?->failedStepClass)->toBe('App\\Jobs\\Foo')
        ->and($failure?->failedStepIndex)->toBe(3)
        ->and($failure?->exception)->toBe($exception);
});

it('returns null from a manifest with no failure recorded', function (): void {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Foo']);

    expect(FailureContext::fromManifest($manifest))->toBeNull();
});

it('preserves the exception reference identity when building from a manifest', function (): void {
    $manifest = PipelineManifest::create(stepClasses: ['App\\Jobs\\Foo']);
    $exception = new RuntimeException('identity-check');

    $manifest->failedStepClass = 'App\\Jobs\\Foo';
    $manifest->failedStepIndex = 0;
    $manifest->failureException = $exception;

    $failure = FailureContext::fromManifest($manifest);

    expect($failure?->exception)->toBe($exception);
});

it('accepts a null exception to model the queued-path carve-out', function (): void {
    $failure = new FailureContext('App\\Jobs\\Foo', 1, null);

    expect($failure->exception)->toBeNull()
        ->and($failure->failedStepClass)->toBe('App\\Jobs\\Foo')
        ->and($failure->failedStepIndex)->toBe(1);
});
