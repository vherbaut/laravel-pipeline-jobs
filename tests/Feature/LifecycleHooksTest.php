<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\HookRecorder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
    HookRecorder::reset();
});

it('fires beforeEach before each step in a sync pipeline', function (): void {
    $seen = [];

    (new PipelineBuilder([TrackExecutionJobA::class, TrackExecutionJobB::class]))
        ->beforeEach(function (StepDefinition $step, ?PipelineContext $ctx) use (&$seen): void {
            $seen[] = $step->jobClass;
        })
        ->send(new SimpleContext)
        ->run();

    expect($seen)->toBe([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->and(TrackExecutionJob::$executionOrder)->toBe([
            TrackExecutionJobA::class,
            TrackExecutionJobB::class,
        ]);
});

it('fires afterEach after each successful step in a sync pipeline', function (): void {
    $seen = [];

    (new PipelineBuilder([TrackExecutionJobA::class, TrackExecutionJobB::class]))
        ->afterEach(function (StepDefinition $step, ?PipelineContext $ctx) use (&$seen): void {
            $seen[] = $step->jobClass;
        })
        ->send(new SimpleContext)
        ->run();

    expect($seen)->toBe([TrackExecutionJobA::class, TrackExecutionJobB::class]);
});

it('fires multiple beforeEach hooks in registration order', function (): void {
    $calls = [];

    (new PipelineBuilder([TrackExecutionJobA::class]))
        ->beforeEach(function () use (&$calls): void {
            $calls[] = 'hook1';
        })
        ->beforeEach(function () use (&$calls): void {
            $calls[] = 'hook2';
        })
        ->send(new SimpleContext)
        ->run();

    expect($calls)->toBe(['hook1', 'hook2']);
});

it('fires multiple afterEach hooks in registration order', function (): void {
    $calls = [];

    (new PipelineBuilder([TrackExecutionJobA::class]))
        ->afterEach(function () use (&$calls): void {
            $calls[] = 'after1';
        })
        ->afterEach(function () use (&$calls): void {
            $calls[] = 'after2';
        })
        ->send(new SimpleContext)
        ->run();

    expect($calls)->toBe(['after1', 'after2']);
});

it('fires multiple onStepFailed hooks in registration order', function (): void {
    $calls = [];

    try {
        (new PipelineBuilder([FailingJob::class]))
            ->onStepFailed(function () use (&$calls): void {
                $calls[] = 'failed1';
            })
            ->onStepFailed(function () use (&$calls): void {
                $calls[] = 'failed2';
            })
            ->send(new SimpleContext)
            ->run();
        fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed) {
        // Expected.
    }

    expect($calls)->toBe(['failed1', 'failed2']);
});

it('does not fire beforeEach or afterEach for steps excluded by when()', function (): void {
    $seen = [];

    (new PipelineBuilder([TrackExecutionJobA::class]))
        ->when(fn (?PipelineContext $ctx) => false, TrackExecutionJobB::class)
        ->beforeEach(function (StepDefinition $step) use (&$seen): void {
            $seen[] = 'before-'.$step->jobClass;
        })
        ->afterEach(function (StepDefinition $step) use (&$seen): void {
            $seen[] = 'after-'.$step->jobClass;
        })
        ->send(new SimpleContext)
        ->run();

    expect($seen)->toBe([
        'before-'.TrackExecutionJobA::class,
        'after-'.TrackExecutionJobA::class,
    ]);
});

it('passes a StepDefinition snapshot whose jobClass matches the executing step', function (): void {
    /** @var StepDefinition|null $captured */
    $captured = null;

    (new PipelineBuilder([TrackExecutionJobA::class]))
        ->beforeEach(function (StepDefinition $step, ?PipelineContext $ctx) use (&$captured): void {
            $captured = $step;
        })
        ->send(new SimpleContext)
        ->run();

    expect($captured)->toBeInstanceOf(StepDefinition::class)
        ->and($captured->jobClass)->toBe(TrackExecutionJobA::class)
        ->and($captured->compensationJobClass)->toBeNull()
        ->and($captured->condition)->toBeNull()
        ->and($captured->queue)->toBeNull()
        ->and($captured->connection)->toBeNull()
        ->and($captured->retry)->toBeNull()
        ->and($captured->timeout)->toBeNull()
        ->and($captured->sync)->toBeFalse();
});

it('passes the live PipelineContext to beforeEach with pre-step mutations visible', function (): void {
    /** @var string|null $captured */
    $captured = null;

    (new PipelineBuilder([EnrichContextJob::class, TrackExecutionJobB::class]))
        ->beforeEach(function (StepDefinition $step, ?PipelineContext $ctx) use (&$captured): void {
            if ($step->jobClass === TrackExecutionJobB::class && $ctx instanceof SimpleContext) {
                $captured = $ctx->name;
            }
        })
        ->send(new SimpleContext)
        ->run();

    expect($captured)->toBe('enriched');
});

it('fires onStepFailed with the step, context, and exception when a step throws', function (): void {
    /** @var array<int, array{string, ?PipelineContext, Throwable}> $calls */
    $calls = [];

    try {
        (new PipelineBuilder([TrackExecutionJobA::class, FailingJob::class]))
            ->onStepFailed(function (StepDefinition $step, ?PipelineContext $ctx, Throwable $e) use (&$calls): void {
                $calls[] = [$step->jobClass, $ctx, $e];
            })
            ->send(new SimpleContext)
            ->run();
        fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed) {
        // Expected.
    }

    expect($calls)->toHaveCount(1);
    [$jobClass, $ctx, $exception] = $calls[0];
    expect($jobClass)->toBe(FailingJob::class)
        ->and($ctx)->toBeInstanceOf(SimpleContext::class)
        ->and($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('Job failed intentionally');
});

it('fires onStepFailed under StopAndCompensate before compensation runs', function (): void {
    $events = [];

    // Pipeline attaches compensation to TrackExecutionJobA (the completed
    // predecessor), so when FailingJob throws, the reverse compensation chain
    // actually invokes CompensateJobA. The hook records "hook:..." and the
    // CompensateJobA::handle() records "compensate:..." into the SAME $events
    // array via a pre-run closure binding, proving hook-before-compensation
    // ordering rather than just hook firing.
    CompensateJobA::$onHandle = function () use (&$events): void {
        $events[] = 'compensate:'.CompensateJobA::class;
    };

    try {
        (new PipelineBuilder)
            ->step(TrackExecutionJobA::class)
            ->compensateWith(CompensateJobA::class)
            ->step(FailingJob::class)
            ->onFailure(FailStrategy::StopAndCompensate)
            ->onStepFailed(function (StepDefinition $step, ?PipelineContext $ctx, Throwable $e) use (&$events): void {
                $events[] = 'hook:'.$step->jobClass;
            })
            ->send(new SimpleContext)
            ->run();
        fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed) {
        // Expected.
    } finally {
        CompensateJobA::$onHandle = null;
    }

    // AC #9: onStepFailed fires once for FailingJob, BEFORE the reverse
    // compensation chain invokes CompensateJobA for the completed
    // TrackExecutionJobA step.
    expect($events)->toBe([
        'hook:'.FailingJob::class,
        'compensate:'.CompensateJobA::class,
    ]);
});

it('fires onStepFailed under SkipAndContinue and then continues execution', function (): void {
    $failedSteps = [];
    $afterSteps = [];

    (new PipelineBuilder([TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class]))
        ->onFailure(FailStrategy::SkipAndContinue)
        ->afterEach(function (StepDefinition $step) use (&$afterSteps): void {
            $afterSteps[] = $step->jobClass;
        })
        ->onStepFailed(function (StepDefinition $step, ?PipelineContext $ctx, Throwable $e) use (&$failedSteps): void {
            $failedSteps[] = $step->jobClass;
        })
        ->send(new SimpleContext)
        ->run();

    expect($failedSteps)->toBe([FailingJob::class])
        ->and($afterSteps)->toBe([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->and(TrackExecutionJob::$executionOrder)->toBe([
            TrackExecutionJobA::class,
            TrackExecutionJobB::class,
        ]);
});

it('treats a throwing beforeEach as a step failure', function (): void {
    $caughtExceptions = [];

    try {
        (new PipelineBuilder([TrackExecutionJobA::class]))
            ->beforeEach(function (): void {
                throw new RuntimeException('hook-boom');
            })
            ->onStepFailed(function (StepDefinition $step, ?PipelineContext $ctx, Throwable $e) use (&$caughtExceptions): void {
                $caughtExceptions[] = $e->getMessage();
            })
            ->send(new SimpleContext)
            ->run();
        fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed) {
        // Expected.
    }

    expect($caughtExceptions)->toBe(['hook-boom'])
        ->and(TrackExecutionJob::$executionOrder)->toBe([]); // the step's handle() never ran
});

it('treats a throwing afterEach as a step failure and does not markStepCompleted', function (): void {
    $caughtExceptions = [];

    // Pipeline::fake()->recording() routes through RecordingExecutor, which
    // pushes to executedSteps AFTER markStepCompleted (AC #10 parity with
    // SyncExecutor). The fake swallows the thrown StepExecutionFailed and
    // returns null, but records the pipeline state so we can inspect
    // executedSteps. A throwing afterEach must leave executedSteps empty
    // for the current step, proving AC #6 load-bearing semantic: the step
    // was NOT marked completed even though handle() ran.
    Pipeline::fake()->recording();

    Pipeline::make([TrackExecutionJobA::class])
        ->afterEach(function (): void {
            throw new RuntimeException('after-boom');
        })
        ->onStepFailed(function (StepDefinition $step, ?PipelineContext $ctx, Throwable $e) use (&$caughtExceptions): void {
            $caughtExceptions[] = $e->getMessage();
        })
        ->send(new SimpleContext)
        ->run();

    $recorded = Pipeline::recordedPipelines()[0] ?? null;

    expect($caughtExceptions)->toBe(['after-boom'])
        ->and(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]) // handle() did run
        ->and($recorded)->not->toBeNull()
        ->and($recorded->executedSteps)->toBe([]); // step was NOT marked completed
});

it('propagates onStepFailed hook exceptions and replaces the original step exception', function (): void {
    try {
        (new PipelineBuilder([FailingJob::class]))
            ->onStepFailed(function (): void {
                throw new LogicException('bad-hook');
            })
            ->send(new SimpleContext)
            ->run();
        fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed $e) {
        // StepExecutionFailed wraps the hook exception because the hook
        // throws AFTER the original exception was caught, and the catch
        // block forwards the hook's throw.
        expect($e->getPrevious())->toBeInstanceOf(LogicException::class)
            ->and($e->getPrevious()->getMessage())->toBe('bad-hook');
    }
});

it('aborts subsequent onStepFailed hooks when one throws', function (): void {
    $secondHookCalls = 0;

    $run = fn () => (new PipelineBuilder([FailingJob::class]))
        ->onStepFailed(function (): void {
            throw new LogicException('first-hook-boom');
        })
        ->onStepFailed(function () use (&$secondHookCalls): void {
            $secondHookCalls++;
        })
        ->send(new SimpleContext)
        ->run();

    expect($run)->toThrow(StepExecutionFailed::class);
    expect($secondHookCalls)->toBe(0); // Second hook must not fire.
});

// --- Story 6.2: Pipeline-level callbacks (sync mode) ---

it('pipeline-level: fires onSuccess once on sync pipeline success', function (): void {
    (new PipelineBuilder([TrackExecutionJobA::class, TrackExecutionJobB::class]))
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->send(new SimpleContext)
        ->run();

    expect(HookRecorder::$fired)->toBe(['onSuccess']);
});

it('pipeline-level: fires onComplete after onSuccess on success', function (): void {
    (new PipelineBuilder([TrackExecutionJobA::class]))
        ->onSuccess(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onSuccess';
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->run();

    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);
});

it('pipeline-level: fires onFailure and onComplete on StopImmediately failure', function (): void {
    $run = fn () => (new PipelineBuilder([TrackExecutionJobA::class, FailingJob::class]))
        ->onFailure(function (?PipelineContext $ctx, Throwable $e): void {
            HookRecorder::$fired[] = 'onFailure';
            HookRecorder::$capturedException = $e;
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->run();

    expect($run)->toThrow(StepExecutionFailed::class);

    expect(HookRecorder::$fired)->toBe(['onFailure', 'onComplete'])
        ->and(HookRecorder::$capturedException)->toBeInstanceOf(RuntimeException::class)
        ->and(HookRecorder::$capturedException->getMessage())->toBe('Job failed intentionally');
});

it('pipeline-level: fires onFailure after compensation under StopAndCompensate', function (): void {
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
            ->run();
        fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed) {
        // Expected.
    } finally {
        CompensateJobA::$onHandle = null;
    }

    // AC #11: compensation runs BEFORE pipeline-level onFailure under sync mode.
    expect(HookRecorder::$fired)->toBe(['compensate', 'onFailure']);
});

it('pipeline-level: does NOT fire onFailure under SkipAndContinue', function (): void {
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
        ->run();

    expect(HookRecorder::$fired)->toBe(['onSuccess', 'onComplete']);
});

it('pipeline-level: does NOT fire pipeline-level callbacks when none are registered', function (): void {
    // Zero-overhead fast path (AC #6, FR37, NFR2). Assertion is the absence
    // of side effects plus the existing test suite remaining green. This
    // test proves that the null-guard branch in firePipelineCallback()
    // executes without touching the HookRecorder.
    (new PipelineBuilder([TrackExecutionJobA::class, TrackExecutionJobB::class]))
        ->send(new SimpleContext)
        ->run();

    expect(HookRecorder::$fired)->toBe([]);
});

it('pipeline-level: preserves onStepFailed firing before pipeline onFailure under StopImmediately', function (): void {
    $run = fn () => (new PipelineBuilder([FailingJob::class]))
        ->onStepFailed(function (StepDefinition $step, ?PipelineContext $ctx, Throwable $e): void {
            HookRecorder::$fired[] = 'onStepFailed';
        })
        ->onFailure(function (?PipelineContext $ctx, Throwable $e): void {
            HookRecorder::$fired[] = 'onFailure';
        })
        ->send(new SimpleContext)
        ->run();

    expect($run)->toThrow(StepExecutionFailed::class);

    // AC #2: onStepFailed fires BEFORE pipeline-level onFailure.
    expect(HookRecorder::$fired)->toBe(['onStepFailed', 'onFailure']);
});

it('pipeline-level: onSuccess throw propagates unwrapped and skips onComplete', function (): void {
    $run = fn () => (new PipelineBuilder([TrackExecutionJobA::class]))
        ->onSuccess(function (?PipelineContext $ctx): void {
            throw new RuntimeException('onSuccess-boom');
        })
        ->onComplete(function (?PipelineContext $ctx): void {
            HookRecorder::$fired[] = 'onComplete';
        })
        ->send(new SimpleContext)
        ->run();

    try {
        $run();
        fail('Expected RuntimeException to be thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('onSuccess-boom');
    }

    expect(HookRecorder::$fired)->toBe([]);
});

it('pipeline-level: onFailure throw wraps as StepExecutionFailed with callback as previous and original step exception preserved', function (): void {
    try {
        (new PipelineBuilder([FailingJob::class]))
            ->onFailure(function (?PipelineContext $ctx, Throwable $e): void {
                throw new LogicException('onFailure-boom');
            })
            ->onComplete(function (?PipelineContext $ctx): void {
                HookRecorder::$fired[] = 'onComplete';
            })
            ->send(new SimpleContext)
            ->run();
        fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed $e) {
        expect($e->getPrevious())->toBeInstanceOf(LogicException::class)
            ->and($e->getPrevious()->getMessage())->toBe('onFailure-boom')
            ->and($e->originalStepException)->toBeInstanceOf(RuntimeException::class)
            ->and($e->originalStepException->getMessage())->toBe('Job failed intentionally');
    }

    // AC #12: onComplete is NOT called when onFailure throws.
    expect(HookRecorder::$fired)->toBe([]);
});

it('pipeline-level: onComplete-after-onFailure throw wraps as StepExecutionFailed and preserves the original step exception', function (): void {
    try {
        (new PipelineBuilder([FailingJob::class]))
            ->onFailure(function (?PipelineContext $ctx, Throwable $e): void {
                HookRecorder::$fired[] = 'onFailure';
            })
            ->onComplete(function (?PipelineContext $ctx): void {
                throw new LogicException('onComplete-boom');
            })
            ->send(new SimpleContext)
            ->run();
        fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed $e) {
        expect($e->getPrevious())->toBeInstanceOf(LogicException::class)
            ->and($e->getPrevious()->getMessage())->toBe('onComplete-boom')
            ->and($e->originalStepException)->toBeInstanceOf(RuntimeException::class)
            ->and($e->originalStepException->getMessage())->toBe('Job failed intentionally');
    }

    // AC #12 failure path: onFailure succeeded, onComplete's throw replaces the
    // intended StepExecutionFailed rethrow via forCallbackFailure so the
    // original step exception stays observable on $originalStepException.
    expect(HookRecorder::$fired)->toBe(['onFailure']);
});
