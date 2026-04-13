<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
});

it('returns null when shouldBeQueued() is used', function (Closure $builderFactory): void {
    $result = $builderFactory()
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    expect($result)->toBeNull();
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(TrackExecutionJobB::class),
]);

it('executes all queued steps in order on the sync driver', function (Closure $builderFactory): void {
    $builderFactory()
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(TrackExecutionJobB::class)
        ->step(TrackExecutionJobC::class),
]);

// AC #5 canary: EnrichContextJob and ReadContextJob now both use the
// InteractsWithPipeline trait, so this scenario also proves the trait
// injection path survives the PipelineStepJob serialize/unserialize
// round-trip on the sync queue driver.
it('propagates context mutations between queued steps', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->name = 'original';

    $builderFactory()
        ->send($context)
        ->shouldBeQueued()
        ->run();

    expect(ReadContextJob::$readName)->toBe('enriched');
})->with([
    'array API' => fn () => new PipelineBuilder([
        EnrichContextJob::class,
        ReadContextJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(EnrichContextJob::class)
        ->step(ReadContextJob::class),
]);

it('stops dispatching subsequent steps when a queued step fails', function (Closure $builderFactory): void {
    expect(fn () => $builderFactory()
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run()
    )->toThrow(RuntimeException::class, 'Job failed intentionally');

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        FailingJob::class,
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobC::class),
]);

it('reflects the shouldBeQueued flag on the built PipelineDefinition', function (Closure $builderFactory): void {
    $definition = $builderFactory()
        ->shouldBeQueued()
        ->build();

    expect($definition->shouldBeQueued)->toBeTrue();
})->with([
    'array API' => fn () => new PipelineBuilder([TrackExecutionJobA::class]),
    'fluent API' => fn () => (new PipelineBuilder)->step(TrackExecutionJobA::class),
]);
