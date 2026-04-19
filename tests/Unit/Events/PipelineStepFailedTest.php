<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('exposes payload via public readonly properties', function () {
    $context = new SimpleContext;
    $context->name = 'run-xyz';
    $exception = new RuntimeException('boom');

    $event = new PipelineStepFailed(
        pipelineId: 'pid-456',
        context: $context,
        stepIndex: 2,
        stepClass: 'App\\Jobs\\DoThing',
        exception: $exception,
    );

    expect($event->pipelineId)->toBe('pid-456')
        ->and($event->context)->toBe($context)
        ->and($event->stepIndex)->toBe(2)
        ->and($event->stepClass)->toBe('App\\Jobs\\DoThing')
        ->and($event->exception)->toBe($exception);
});

it('accepts a null context', function () {
    $event = new PipelineStepFailed(
        pipelineId: 'pid-null',
        context: null,
        stepIndex: 0,
        stepClass: 'App\\Jobs\\DoThing',
        exception: new RuntimeException('err'),
    );

    expect($event->context)->toBeNull();
});

it('enforces readonly properties', function () {
    $event = new PipelineStepFailed(
        pipelineId: 'pid',
        context: null,
        stepIndex: 1,
        stepClass: 'App\\Jobs\\Other',
        exception: new RuntimeException('err'),
    );

    $attempt = static fn () => $event->stepIndex = 99;

    expect($attempt)->toThrow(Error::class);
});
