<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('exposes payload via public readonly properties', function () {
    $context = new SimpleContext;
    $context->name = 'run-xyz';

    $event = new PipelineStepCompleted(
        pipelineId: 'pid-123',
        context: $context,
        stepIndex: 4,
        stepClass: 'App\\Jobs\\DoThing',
    );

    expect($event->pipelineId)->toBe('pid-123')
        ->and($event->context)->toBe($context)
        ->and($event->stepIndex)->toBe(4)
        ->and($event->stepClass)->toBe('App\\Jobs\\DoThing');
});

it('accepts a null context', function () {
    $event = new PipelineStepCompleted(
        pipelineId: 'pid-null',
        context: null,
        stepIndex: 0,
        stepClass: 'App\\Jobs\\DoThing',
    );

    expect($event->context)->toBeNull();
});

it('enforces readonly properties', function () {
    $event = new PipelineStepCompleted(
        pipelineId: 'pid',
        context: null,
        stepIndex: 1,
        stepClass: 'App\\Jobs\\Other',
    );

    $attempt = static fn () => $event->pipelineId = 'mutated';

    expect($attempt)->toThrow(Error::class);
});
