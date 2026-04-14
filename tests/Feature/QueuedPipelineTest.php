<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\HookRecorder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\IncrementCountJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
    HookRecorder::reset();
    CompensateJobA::$executed = [];
    CompensateJobA::$onHandle = null;
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

it('returns null immediately even when ->return() is registered on a queued pipeline', function (Closure $builderFactory): void {
    $closureCallCount = 0;

    $result = $builderFactory()
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->return(function (?PipelineContext $ctx) use (&$closureCallCount): int {
            $closureCallCount++;

            return $ctx instanceof SimpleContext ? $ctx->count : -1;
        })
        ->run();

    // AC #4: queued pipelines always return null; ->return() is sync-only and
    // must not be silently evaluated against a partially-executed context.
    expect($result)->toBeNull()
        ->and($closureCallCount)->toBe(0);
})->with([
    'array API' => fn () => new PipelineBuilder([
        IncrementCountJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(IncrementCountJob::class),
]);

// --- Story 6.1: Per-step lifecycle hooks in queued mode ---

it('fires beforeEach and afterEach on the worker process in a queued pipeline', function (): void {
    (new PipelineBuilder([TrackExecutionJobA::class, TrackExecutionJobB::class]))
        ->beforeEach(function (StepDefinition $step): void {
            HookRecorder::$beforeEach[] = $step->jobClass;
        })
        ->afterEach(function (StepDefinition $step): void {
            HookRecorder::$afterEach[] = $step->jobClass;
        })
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    expect(HookRecorder::$beforeEach)->toBe([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->and(HookRecorder::$afterEach)->toBe([TrackExecutionJobA::class, TrackExecutionJobB::class]);
});

it('serializes hook closures across an explicit serialize/unserialize roundtrip on the PipelineStepJob payload', function (): void {
    // Sync driver runs jobs in-process and does NOT serialize payloads, so
    // the standard -&gt;shouldBeQueued()-&gt;run() path cannot prove queue-transport
    // safety. Build the same job the dispatcher would, serialize and
    // unserialize it explicitly (mirroring what a real queue backend does
    // on the worker), then handle() the restored instance and assert the
    // hook fires with the restored closure. This proves AC #5
    // (SerializableClosure round-trip) without depending on a specific
    // queue backend.
    $builder = (new PipelineBuilder([TrackExecutionJobA::class]))
        ->beforeEach(function (StepDefinition $step): void {
            HookRecorder::$order[] = 'before:'.$step->jobClass;
        })
        ->send(new SimpleContext);

    $definition = $builder->build();

    $manifest = \Vherbaut\LaravelPipelineJobs\Context\PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
        context: new SimpleContext,
    );
    $manifest->beforeEachHooks = array_map(
        static fn ($hook) => new \Laravel\SerializableClosure\SerializableClosure($hook),
        $definition->beforeEachHooks,
    );

    $job = new \Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob($manifest);
    $restored = unserialize(serialize($job));

    $restored->handle();

    expect(HookRecorder::$order)->toBe(['before:'.TrackExecutionJobA::class]);
});

it('fires onStepFailed in queued mode under SkipAndContinue without marking the wrapper failed', function (): void {
    (new PipelineBuilder([TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class]))
        ->onFailure(FailStrategy::SkipAndContinue)
        ->afterEach(function (StepDefinition $step): void {
            HookRecorder::$afterEach[] = $step->jobClass;
        })
        ->onStepFailed(function (StepDefinition $step): void {
            HookRecorder::$onStepFailed[] = $step->jobClass;
        })
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    expect(HookRecorder::$onStepFailed)->toBe([FailingJob::class])
        ->and(HookRecorder::$afterEach)->toBe([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->and(TrackExecutionJob::$executionOrder)->toBe([
            TrackExecutionJobA::class,
            TrackExecutionJobB::class,
        ]);
});

it('fires onStepFailed in queued mode under StopImmediately before rethrow', function (): void {
    try {
        (new PipelineBuilder([FailingJob::class]))
            ->onStepFailed(function (StepDefinition $step): void {
                HookRecorder::$onStepFailed[] = $step->jobClass;
            })
            ->send(new SimpleContext)
            ->shouldBeQueued()
            ->run();
        fail('Expected RuntimeException to bubble up from the sync driver');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Job failed intentionally');
    }

    expect(HookRecorder::$onStepFailed)->toBe([FailingJob::class]);
});

it('fires onStepFailed in queued mode under StopAndCompensate before dispatching the compensation chain', function (): void {
    // CompensateJobA::$onHandle appends to HookRecorder::$order the moment
    // the compensation runs. The onStepFailed hook appends a "hook:" entry
    // into the same ordered array. With the sync queue driver the whole
    // chain (failure, hook, compensation dispatch, compensation execute)
    // unfolds in-process, so the relative order of the two entries is a
    // faithful witness to AC #9 queued-path ordering.
    CompensateJobA::$onHandle = function (): void {
        HookRecorder::$order[] = 'compensate:'.CompensateJobA::class;
    };

    try {
        (new PipelineBuilder)
            ->step(TrackExecutionJobA::class)
            ->compensateWith(CompensateJobA::class)
            ->step(FailingJob::class)
            ->onFailure(FailStrategy::StopAndCompensate)
            ->onStepFailed(function (StepDefinition $step): void {
                HookRecorder::$order[] = 'hook:'.$step->jobClass;
            })
            ->send(new SimpleContext)
            ->shouldBeQueued()
            ->run();
        fail('Expected RuntimeException to bubble up from the sync driver');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Job failed intentionally');
    }

    expect(HookRecorder::$order)->toBe([
        'hook:'.FailingJob::class,
        'compensate:'.CompensateJobA::class,
    ])->and(CompensateJobA::$executed)->toBe([CompensateJobA::class]);
});
