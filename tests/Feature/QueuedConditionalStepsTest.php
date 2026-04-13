<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\SetActiveJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    TrackExecutionJob::$executionOrder = [];
});

it('skips queued conditional steps whose condition is false and continues the chain', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->active = false;

    $builderFactory()
        ->send($context)
        ->shouldBeQueued()
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobC::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::when(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class),
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->when(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class)
        ->step(TrackExecutionJobC::class),
]);

it('evaluates queued conditions against context mutated by an earlier queued step', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->active = false;

    $builderFactory()
        ->send($context)
        ->shouldBeQueued()
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        SetActiveJob::class,
        TrackExecutionJobB::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        SetActiveJob::class,
        Step::when(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class),
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(SetActiveJob::class)
        ->when(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class),
]);

it('runs queued conditional steps when the condition is true', function (): void {
    $context = new SimpleContext;
    $context->active = true;

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->when(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class)
        ->send($context)
        ->shouldBeQueued()
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]);
});
