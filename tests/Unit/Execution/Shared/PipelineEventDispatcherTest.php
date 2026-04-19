<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\PipelineEventDispatcher;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

beforeEach(function (): void {
    Event::fake([
        PipelineStepCompleted::class,
        PipelineStepFailed::class,
        PipelineCompleted::class,
    ]);
});

it('fireStepCompleted is a no-op when dispatchEvents flag is off', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A'],
        dispatchEvents: false,
    );

    PipelineEventDispatcher::fireStepCompleted($manifest, 0, 'App\\Jobs\\A');

    Event::assertNotDispatched(PipelineStepCompleted::class);
});

it('fireStepCompleted dispatches the event with correct payload when dispatchEvents is on', function (): void {
    $context = new SimpleContext;
    $context->name = 'ctx-ref';

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A'],
        context: $context,
        dispatchEvents: true,
    );

    PipelineEventDispatcher::fireStepCompleted($manifest, 2, 'App\\Jobs\\A');

    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event): bool => $event->pipelineId === $manifest->pipelineId
            && $event->context === $context
            && $event->stepIndex === 2
            && $event->stepClass === 'App\\Jobs\\A',
    );
});

it('fireStepFailed is a no-op when dispatchEvents flag is off', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A'],
        dispatchEvents: false,
    );

    PipelineEventDispatcher::fireStepFailed($manifest, 0, 'App\\Jobs\\A', new RuntimeException('boom'));

    Event::assertNotDispatched(PipelineStepFailed::class);
});

it('fireStepFailed dispatches the event with the live Throwable when dispatchEvents is on', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A'],
        dispatchEvents: true,
    );
    $exception = new RuntimeException('boom');

    PipelineEventDispatcher::fireStepFailed($manifest, 3, 'App\\Jobs\\A', $exception);

    Event::assertDispatched(
        PipelineStepFailed::class,
        fn (PipelineStepFailed $event): bool => $event->pipelineId === $manifest->pipelineId
            && $event->stepIndex === 3
            && $event->stepClass === 'App\\Jobs\\A'
            && $event->exception === $exception,
    );
});

it('fireCompleted is a no-op when dispatchEvents flag is off', function (): void {
    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A'],
        dispatchEvents: false,
    );

    PipelineEventDispatcher::fireCompleted($manifest);

    Event::assertNotDispatched(PipelineCompleted::class);
});

it('fireCompleted dispatches the event with correct payload when dispatchEvents is on', function (): void {
    $context = new SimpleContext;
    $context->name = 'terminal-ctx';

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\A'],
        context: $context,
        dispatchEvents: true,
    );

    PipelineEventDispatcher::fireCompleted($manifest);

    Event::assertDispatched(
        PipelineCompleted::class,
        fn (PipelineCompleted $event): bool => $event->pipelineId === $manifest->pipelineId
            && $event->context === $context,
    );
});
