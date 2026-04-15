<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Laravel\SerializableClosure\SerializableClosure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingThenSucceedingJob;
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
    FailingThenSucceedingJob::reset();
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

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
        context: new SimpleContext,
    );
    $manifest->beforeEachHooks = array_map(
        static fn ($hook) => new SerializableClosure($hook),
        $definition->beforeEachHooks,
    );

    $job = new PipelineStepJob($manifest);
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

// --- Story 6.2: Pipeline-level callbacks (queued mode) ---

it('pipeline-level: fires onSuccess and onComplete on last queued wrapper success', function (): void {
    (new PipelineBuilder([TrackExecutionJobA::class]))
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);
});

it('pipeline-level: fires onSuccess only once after the last of a multi-step queued pipeline', function (): void {
    (new PipelineBuilder([TrackExecutionJobA::class, TrackExecutionJobB::class, TrackExecutionJobC::class]))
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    // AC #7: onSuccess fires once on the terminal wrapper, NOT on earlier ones.
    expect(HookRecorder::$fired)->toBe(['onSuccess']);
});

it('pipeline-level: fires onFailure and onComplete on queued StopImmediately failure', function (): void {
    try {
        (new PipelineBuilder([TrackExecutionJobA::class, FailingJob::class]))
            ->onSuccess(function (?PipelineContext $ctx): void {
                HookRecorder::$fired[] = 'onSuccess';
            })
            ->onFailure(function (?PipelineContext $ctx, Throwable $e): void {
                HookRecorder::$fired[] = 'onFailure';
            })
            ->onComplete(function (?PipelineContext $ctx): void {
                HookRecorder::$fired[] = 'onComplete';
            })
            ->send(new SimpleContext)
            ->shouldBeQueued()
            ->run();
        fail('Expected RuntimeException to bubble up from the sync driver');
    } catch (RuntimeException) {
        // Expected: sync queue driver bubbles the step exception.
    }

    // AC #2, #3, #5: onFailure fires, then onComplete; onSuccess does NOT.
    expect(HookRecorder::$fired)->toBe(['onFailure', 'onComplete']);
});

it('pipeline-level: fires onFailure after compensation dispatch in queued StopAndCompensate', function (): void {
    CompensateJobA::$onHandle = function (): void {
        HookRecorder::$fired[] = 'compensate';
    };

    try {
        (new PipelineBuilder)
            ->step(TrackExecutionJobA::class)
            ->compensateWith(CompensateJobA::class)
            ->step(FailingJob::class)
            ->onFailure(FailStrategy::StopAndCompensate)
            ->onFailure(function (?PipelineContext $ctx, Throwable $e): void {
                HookRecorder::$fired[] = 'onFailure';
            })
            ->send(new SimpleContext)
            ->shouldBeQueued()
            ->run();
        fail('Expected RuntimeException to bubble up');
    } catch (RuntimeException) {
        // Expected.
    } finally {
        CompensateJobA::$onHandle = null;
    }

    // AC #11 queued clause: onFailure fires AFTER compensation Bus::chain
    // dispatch. Because the sync queue driver executes the chain in-process,
    // the compensation job runs before the rethrow from the failing wrapper's
    // handle() completes and bubbles up — so 'compensate' appears in the
    // recording before 'onFailure'.
    expect(HookRecorder::$fired)->toBe(['compensate', 'onFailure']);
});

it('pipeline-level: does NOT fire onFailure under queued SkipAndContinue', function (): void {
    (new PipelineBuilder([TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class]))
        ->onFailure(FailStrategy::SkipAndContinue)
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->onFailure(function (?PipelineContext $ctx, Throwable $e): void {
            HookRecorder::$fired[] = 'onFailure';
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    // AC #10: pipeline terminates via the success tail of the last wrapper,
    // so onSuccess + onComplete fire; onFailure does NOT fire.
    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);
});

it('pipeline-level: callback closures survive a serialize/unserialize roundtrip on PipelineStepJob', function (): void {
    // AC #7 direct proof-point: construct a PipelineStepJob wrapping a manifest
    // with all three callback slots populated, serialize/unserialize the job
    // (mirroring what Laravel's queue does on dispatch + worker pickup), then
    // invoke handle() on the reconstructed job and assert callbacks fire.
    // This explicitly exercises the SerializableClosure queue boundary rather
    // than relying on sync-driver implicit behavior from earlier tests.
    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
        context: new SimpleContext,
    );

    $manifest->onSuccessCallback = new SerializableClosure(function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onSuccess';
    });
    $manifest->onFailureCallback = new SerializableClosure(function (?PipelineContext $ctx, Throwable $e): void {
        HookRecorder::$fired[] = 'onFailure';
    });
    $manifest->onCompleteCallback = new SerializableClosure(function (?PipelineContext $ctx): void {
        HookRecorder::$fired[] = 'onComplete';
    });

    $job = new PipelineStepJob($manifest);
    $rehydrated = unserialize(serialize($job));

    expect($rehydrated)->toBeInstanceOf(PipelineStepJob::class)
        ->and($rehydrated->manifest->onSuccessCallback)->toBeInstanceOf(SerializableClosure::class)
        ->and($rehydrated->manifest->onFailureCallback)->toBeInstanceOf(SerializableClosure::class)
        ->and($rehydrated->manifest->onCompleteCallback)->toBeInstanceOf(SerializableClosure::class);

    $rehydrated->handle();

    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);
});

it('pipeline-level: fires terminal callbacks when last queued step fails under SkipAndContinue', function (): void {
    // P1 regression: the terminal wrapper must fire onSuccess + onComplete
    // when advanceStep moves past the last step under SkipAndContinue.
    // Before the fix, the handler returned silently without firing callbacks.
    (new PipelineBuilder([TrackExecutionJobA::class, FailingJob::class]))
        ->onFailure(FailStrategy::SkipAndContinue)
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    // AC #10: SkipAndContinue pipelines always terminate via the success tail,
    // so both terminal callbacks fire even when the last step failed.
    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);
});

it('pipeline-level: fires terminal callbacks when last queued step is conditionally skipped', function (): void {
    // P2 regression: a last step whose when()/unless() predicate excludes it
    // still terminates the pipeline on this wrapper and must fire the
    // terminal callbacks. Before the fix, the skip branch returned silently.
    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->unless(fn (?PipelineContext $ctx): bool => true, TrackExecutionJobB::class)
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);
});

it('pipeline-level: queued onSuccess throw wraps as StepExecutionFailed with original step exception preserved', function (): void {
    // P5 coverage + AC #5 queued parity: a throwing onSuccess on the terminal
    // wrapper must land in failed_jobs as StepExecutionFailed. On the success
    // tail there is no prior step exception, so $originalStepException is null.
    try {
        (new PipelineBuilder([TrackExecutionJobA::class]))
            ->onSuccess(function (?PipelineContext $ctx): void {
                throw new LogicException('onSuccess-boom');
            })
            ->onComplete(function (?PipelineContext $ctx): void {
                HookRecorder::$fired[] = 'onComplete';
            })
            ->send(new SimpleContext)
            ->shouldBeQueued()
            ->run();
        fail('Expected LogicException to bubble up');
    } catch (LogicException $e) {
        // Success tail throws propagate unwrapped (no prior step exception to
        // wrap against); Laravel marks the wrapper failed with the callback
        // exception per AC #12 queued clause.
        expect($e->getMessage())->toBe('onSuccess-boom');
    }

    // onComplete is NOT called when onSuccess throws.
    expect(HookRecorder::$fired)->toBe([]);
});

// Story 7.1 note: Bus::fake() captures both dispatch() and dispatch_sync()
// calls, but into separate assertion channels: Bus::assertDispatched() for
// async and Bus::assertDispatchedSync() for sync. The sync-step tests below
// rely on Bus::assertDispatchedSync() as the signal that dispatch_sync() was
// the branch taken by dispatchFirstStep() / dispatchNextStep() (AC #11).

it('per-step queue: dispatches the first step to its configured queue under Bus::fake()', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->onQueue('heavy')
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === 'heavy',
    );
});

it('per-step queue: first-step dispatch carries stepConfigs[0].queue under Bus::fake', function (): void {
    // Bus::fake blocks PipelineStepJob::handle(), so the self-dispatch of
    // subsequent steps never fires here; only the first dispatch is observable.
    // Subsequent-step routing via dispatchNextStep() is covered by
    // tests/Unit/Execution/PipelineStepJobTest.php.
    Bus::fake();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->onQueue('first-queue')
        ->step(TrackExecutionJobB::class)->onQueue('second-queue')
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatchedTimes(PipelineStepJob::class, 1);
    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === 'first-queue'
            && $job->manifest->currentStepIndex === 0,
    );
});

it('per-step queue: dispatches with both onQueue and onConnection overrides', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->onQueue('heavy')->onConnection('redis')
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === 'heavy'
            && $job->connection === 'redis',
    );
});

it('per-step queue: null queue falls through to Laravel default (no onQueue applied)', function (): void {
    Bus::fake();

    (new PipelineBuilder([TrackExecutionJobA::class]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === null
            && $job->connection === null,
    );
});

it('sync step: uses dispatch_sync when first step is marked sync', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->sync()
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    // Bus::assertDispatchedSync proves dispatch_sync() was the branch taken.
    // BusFake records sync dispatches in a separate channel from async
    // dispatches, so this assertion fails if the executor had fallen back
    // to dispatch() (AC #11).
    Bus::assertDispatchedSync(PipelineStepJob::class);
});

it('sync step: dispatches via dispatch_sync without applying onQueue (queue routing is irrelevant for sync)', function (): void {
    Bus::fake();

    // Even when onQueue is declared alongside sync, dispatchFirstStep() takes
    // the sync branch and does not invoke onQueue on a PendingDispatch.
    // Wrapper's $queue property stays null in the captured sync dispatch.
    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->onQueue('heavy')->sync()
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatchedSync(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === null,
    );
});

it('sync step: exception propagates synchronously under StopImmediately when first step is sync', function (): void {
    // The sync step throws; dispatch_sync (via Bus::dispatchSync under
    // Bus::fake) surfaces the exception into QueuedExecutor::dispatchFirstStep()'s
    // caller (AC #11). Without Bus::fake the semantics are identical because
    // dispatch_sync bypasses the queue layer entirely.
    expect(function (): void {
        (new PipelineBuilder)
            ->step(FailingJob::class)->sync()
            ->send(new SimpleContext)
            ->shouldBeQueued()
            ->run();
    })->toThrow(RuntimeException::class);
});

it('default queue: step without explicit onQueue inherits pipeline default', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->defaultQueue('background')
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === 'background',
    );
});

it('default queue: explicit step-level override wins over pipeline default', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->onQueue('heavy')
        ->step(TrackExecutionJobB::class)
        ->defaultQueue('background')
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    // Only the FIRST wrapper actually dispatches under Bus::fake() (the
    // second self-dispatch never fires because the first wrapper's handle()
    // never runs). Assert the first wrapper carries the step-level override.
    Bus::assertDispatchedTimes(PipelineStepJob::class, 1);
    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === 'heavy',
    );
});

it('default queue: absent default preserves Laravel default when no step override is declared', function (): void {
    Bus::fake();

    (new PipelineBuilder([TrackExecutionJobA::class]))
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === null,
    );
});

it('default connection: step inherits defaultConnection when no explicit override is declared', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->defaultConnection('redis')
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->connection === 'redis',
    );
});

it('default queue: declaration order is irrelevant (declared before any step still resolves)', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->defaultQueue('background')
        ->step(TrackExecutionJobA::class)
        ->send(new SimpleContext)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === 'background',
    );
});

// --- Story 7.2: per-step retry & timeout ---

it('per-step retry (queued): in-process retry under sync queue driver', function (): void {
    // The beforeEach sets the sync queue driver, so handle() runs inline and
    // the in-process retry loop actually executes.
    FailingThenSucceedingJob::reset();
    FailingThenSucceedingJob::$attemptsBeforeSuccess = 1;

    (new PipelineBuilder)
        ->step(FailingThenSucceedingJob::class)->retry(2)
        ->shouldBeQueued()
        ->run();

    expect(FailingThenSucceedingJob::$invocationCount)->toBe(2);
});

it('per-step retry (queued): dispatches wrapper with timeout property set', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->timeout(60)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->timeout === 60,
    );
});

it('per-step retry (queued): null timeout preserves Laravel default (no timeout set on wrapper)', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->timeout === null,
    );
});

it('default timeout: step inherits pipeline-level defaultTimeout', function (): void {
    Bus::fake();

    (new PipelineBuilder)
        ->defaultTimeout(60)
        ->step(TrackExecutionJobA::class)
        ->shouldBeQueued()
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->timeout === 60,
    );
});
