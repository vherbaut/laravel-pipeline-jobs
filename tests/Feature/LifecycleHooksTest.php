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
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
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
