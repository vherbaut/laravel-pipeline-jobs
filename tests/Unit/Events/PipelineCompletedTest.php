<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('exposes payload via public readonly properties', function () {
    $context = new SimpleContext;
    $context->name = 'finish-line';

    $event = new PipelineCompleted(
        pipelineId: 'pid-789',
        context: $context,
    );

    expect($event->pipelineId)->toBe('pid-789')
        ->and($event->context)->toBe($context);
});

it('accepts a null context', function () {
    $event = new PipelineCompleted(
        pipelineId: 'pid-null',
        context: null,
    );

    expect($event->context)->toBeNull();
});

it('enforces readonly properties', function () {
    $event = new PipelineCompleted(
        pipelineId: 'pid',
        context: null,
    );

    $attempt = static fn () => $event->pipelineId = 'mutated';

    expect($attempt)->toThrow(Error::class);
});
