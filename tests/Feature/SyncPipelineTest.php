<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
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
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
    FailingThenSucceedingJob::reset();
    HookRecorder::reset();
});

it('executes all steps in order', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    $builderFactory()
        ->send($context)
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

it('gives each job the same PipelineContext instance', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    $result = $builderFactory()
        ->send($context)
        ->run();

    expect($result)->toBe($context);
})->with([
    'array API' => fn () => new PipelineBuilder([
        EnrichContextJob::class,
        ReadContextJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(EnrichContextJob::class)
        ->step(ReadContextJob::class),
]);

it('passes context enriched by step N to step N+1', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->name = 'original';

    $builderFactory()
        ->send($context)
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

it('stops on first failure and does not execute subsequent steps', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    expect(fn () => $builderFactory()
        ->send($context)
        ->run()
    )->toThrow(StepExecutionFailed::class);

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

it('wraps exception in StepExecutionFailed with pipeline context', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    try {
        $builderFactory()
            ->send($context)
            ->run();

        $this->fail('Expected StepExecutionFailed to be thrown');
    } catch (StepExecutionFailed $exception) {
        expect($exception->getMessage())->toContain('step 1')
            ->and($exception->getMessage())->toContain(FailingJob::class)
            ->and($exception->getMessage())->toContain('Job failed intentionally')
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        FailingJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(FailingJob::class),
]);

it('resolves closure context before first step executes', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->name = 'from-closure';

    $result = $builderFactory()
        ->send(fn () => $context)
        ->run();

    expect($result)->toBe($context)
        ->and(ReadContextJob::$readName)->toBe('from-closure');
})->with([
    'array API' => fn () => new PipelineBuilder([
        ReadContextJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(ReadContextJob::class),
]);

it('returns the final PipelineContext after all steps', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    $result = $builderFactory()
        ->send($context)
        ->run();

    expect($result)
        ->toBeInstanceOf(PipelineContext::class)
        ->toBe($context)
        ->and($result->name)->toBe('enriched');
})->with([
    'array API' => fn () => new PipelineBuilder([
        EnrichContextJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(EnrichContextJob::class),
]);

it('executes steps with null context when no send() is called', function (Closure $builderFactory): void {
    $result = $builderFactory()
        ->run();

    expect($result)->toBeNull()
        ->and(TrackExecutionJob::$executionOrder)->toBe([
            TrackExecutionJobA::class,
            TrackExecutionJobB::class,
        ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(TrackExecutionJobB::class),
]);

it('throws InvalidPipelineDefinition on empty builder', function (): void {
    expect(fn () => (new PipelineBuilder)->run())
        ->toThrow(InvalidPipelineDefinition::class, 'A pipeline must contain at least one step.');
});

// --- return() ---

it('returns the closure result when ->return() is registered', function (Closure $builderFactory): void {
    $result = $builderFactory()
        ->send(new SimpleContext)
        ->return(fn (?PipelineContext $ctx) => $ctx instanceof SimpleContext ? $ctx->count : null)
        ->run();

    expect($result)->toBe(1);
})->with([
    'array API' => fn () => new PipelineBuilder([
        IncrementCountJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(IncrementCountJob::class),
]);

it('returns the PipelineContext when ->return() is not registered', function (Closure $builderFactory): void {
    $result = $builderFactory()
        ->send(new SimpleContext)
        ->run();

    expect($result)->toBeInstanceOf(SimpleContext::class)
        ->and($result->count)->toBe(1);
})->with([
    'array API' => fn () => new PipelineBuilder([
        IncrementCountJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(IncrementCountJob::class),
]);

it('passes null to the return closure when no context was sent', function (Closure $builderFactory): void {
    $result = $builderFactory()
        ->return(fn (?PipelineContext $ctx) => $ctx === null ? 'empty' : 'filled')
        ->run();

    expect($result)->toBe('empty');
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class),
]);

it('propagates exceptions thrown by the return closure', function (Closure $builderFactory): void {
    $builder = $builderFactory()
        ->send(new SimpleContext)
        ->return(fn () => throw new RuntimeException('boom'));

    expect(fn () => $builder->run())->toThrow(RuntimeException::class, 'boom');
})->with([
    'array API' => fn () => new PipelineBuilder([
        IncrementCountJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(IncrementCountJob::class),
]);

it('applies the most recent return closure when ->return() is called twice', function (Closure $builderFactory): void {
    $result = $builderFactory()
        ->send(new SimpleContext)
        ->return(fn (?PipelineContext $ctx) => 'first')
        ->return(fn (?PipelineContext $ctx) => $ctx instanceof SimpleContext ? $ctx->count * 10 : 'fallback')
        ->run();

    expect($result)->toBe(10);
})->with([
    'array API' => fn () => new PipelineBuilder([
        IncrementCountJob::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(IncrementCountJob::class),
]);

it('per-step retry: step succeeds on second attempt after one failure', function () {
    FailingThenSucceedingJob::$attemptsBeforeSuccess = 1;

    (new PipelineBuilder)
        ->step(FailingThenSucceedingJob::class)->retry(2)
        ->run();

    expect(FailingThenSucceedingJob::$invocationCount)->toBe(2);
});

it('per-step retry: step fails all attempts and surfaces final exception under StopImmediately', function () {
    expect(fn () => (new PipelineBuilder)
        ->step(FailingJob::class)->retry(2)
        ->run()
    )->toThrow(StepExecutionFailed::class);
});

it('per-step retry: backoff sleeps between attempts', function () {
    FailingThenSucceedingJob::$attemptsBeforeSuccess = 1;

    (new PipelineBuilder)
        ->step(FailingThenSucceedingJob::class)->retry(1)->backoff(1)
        ->run();

    // Time-sensitive: asserts the second attempt occurred >= 1s after the first.
    $timestamps = FailingThenSucceedingJob::$invocationTimestamps;
    expect($timestamps)->toHaveCount(2)
        ->and($timestamps[1] - $timestamps[0])->toBeGreaterThanOrEqual(1.0);
});

it('per-step retry: hooks fire ONCE per step, not per attempt', function () {
    FailingThenSucceedingJob::$attemptsBeforeSuccess = 2;

    (new PipelineBuilder)
        ->step(FailingThenSucceedingJob::class)->retry(3)
        ->beforeEach(function (StepDefinition $step) {
            HookRecorder::$beforeEach[] = $step->jobClass;
        })
        ->afterEach(function (StepDefinition $step) {
            HookRecorder::$afterEach[] = $step->jobClass;
        })
        ->onStepFailed(function (StepDefinition $step) {
            HookRecorder::$onStepFailed[] = $step->jobClass;
        })
        ->run();

    expect(FailingThenSucceedingJob::$invocationCount)->toBe(3)
        ->and(HookRecorder::$beforeEach)->toHaveCount(1)
        ->and(HookRecorder::$afterEach)->toHaveCount(1)
        ->and(HookRecorder::$onStepFailed)->toHaveCount(0);
});

it('per-step retry: onStepFailed fires once on exhaustion under StopImmediately', function () {
    try {
        (new PipelineBuilder)
            ->step(FailingJob::class)->retry(2)
            ->onStepFailed(function (StepDefinition $step) {
                HookRecorder::$onStepFailed[] = $step->jobClass;
            })
            ->run();
    } catch (StepExecutionFailed) {
        // expected
    }

    expect(HookRecorder::$onStepFailed)->toBe([FailingJob::class]);
});

it('per-step retry: retry under SkipAndContinue still calls onStepFailed once on exhaustion and advances', function () {
    FailingThenSucceedingJob::$attemptsBeforeSuccess = 99; // always fails

    (new PipelineBuilder)
        ->step(FailingThenSucceedingJob::class)->retry(1)
        ->step(TrackExecutionJobA::class)
        ->onFailure(FailStrategy::SkipAndContinue)
        ->onStepFailed(function (StepDefinition $step) {
            HookRecorder::$onStepFailed[] = $step->jobClass;
        })
        ->run();

    expect(FailingThenSucceedingJob::$invocationCount)->toBe(2)
        ->and(HookRecorder::$onStepFailed)->toBe([FailingThenSucceedingJob::class])
        ->and(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);
});

it('per-step retry: zero-overhead fast path when retry is null (single invocation)', function () {
    FailingThenSucceedingJob::$attemptsBeforeSuccess = 0;

    (new PipelineBuilder)
        ->step(FailingThenSucceedingJob::class)
        ->run();

    expect(FailingThenSucceedingJob::$invocationCount)->toBe(1);
});

it('default retry: step without explicit retry inherits pipeline default', function () {
    FailingThenSucceedingJob::$attemptsBeforeSuccess = 2;

    (new PipelineBuilder)
        ->defaultRetry(2)
        ->step(FailingThenSucceedingJob::class)
        ->run();

    expect(FailingThenSucceedingJob::$invocationCount)->toBe(3);
});

it('default retry: explicit step-level retry overrides pipeline default', function () {
    FailingThenSucceedingJob::$attemptsBeforeSuccess = 3;

    (new PipelineBuilder)
        ->defaultRetry(1)
        ->step(FailingThenSucceedingJob::class)->retry(3)
        ->run();

    expect(FailingThenSucceedingJob::$invocationCount)->toBe(4);
});

it('default retry / backoff / timeout: declaration order independent', function () {
    $definition = (new PipelineBuilder)
        ->defaultRetry(2)
        ->step(TrackExecutionJobA::class)
        ->defaultBackoff(1)
        ->build();

    $configs = PipelineBuilder::resolveStepConfigs($definition);

    expect($configs[0]['retry'])->toBe(2)
        ->and($configs[0]['backoff'])->toBe(1)
        ->and($configs[0]['timeout'])->toBeNull();
});
