<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\StepExecutionFailedThrowingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];
});

it('runs inner steps sequentially with context enrichment surviving after the nested group', function (Closure $builderFactory): void {
    $context = new SimpleContext;

    $result = $builderFactory()
        ->send($context)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ])->and($result)->toBeInstanceOf(SimpleContext::class)
        ->and($result->name)->toBe('enriched');
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::nest(JobPipeline::make([TrackExecutionJobB::class, EnrichContextJob::class])),
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->nest(JobPipeline::make([TrackExecutionJobB::class, EnrichContextJob::class]))
        ->step(TrackExecutionJobC::class),
]);

it('advances the outer position exactly once for a nested group (flatStepCount expands inner steps)', function (): void {
    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::nest(JobPipeline::make([TrackExecutionJobB::class, TrackExecutionJobC::class])),
    ]);

    $definition = $builder->build();

    expect($definition->stepCount())->toBe(2)
        ->and($definition->flatStepCount())->toBe(3);
});

it('supports multi-level (three-level) nesting with correct declaration order execution', function (): void {
    $innermost = JobPipeline::make([TrackExecutionJobB::class, EnrichContextJob::class]);
    $middle = JobPipeline::make([TrackExecutionJobA::class])->nest($innermost);

    $result = (new PipelineBuilder)
        ->nest($middle)
        ->step(TrackExecutionJobC::class)
        ->send(new SimpleContext)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ])->and($result->name)->toBe('enriched');
});

it('rejects nested pipeline embedded inside a parallel group at build time', function (): void {
    $nested = JobPipeline::nest(JobPipeline::make([TrackExecutionJobA::class]));

    expect(fn () => JobPipeline::parallel([TrackExecutionJobB::class, $nested]))
        ->toThrow(InvalidPipelineDefinition::class, 'Nested pipelines cannot be embedded inside parallel step groups');
});

it('supports a parallel sub-group inside a nested pipeline', function (): void {
    $inner = JobPipeline::make([TrackExecutionJobA::class])
        ->parallel([TrackExecutionJobB::class, TrackExecutionJobC::class]);

    (new PipelineBuilder)
        ->nest($inner)
        ->send(new SimpleContext)
        ->run();

    // TrackExecutionJobA fires first (flat inner step), then the two parallel
    // sub-steps run sequentially inside sync mode (order is declaration order).
    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

it('aborts remaining inner steps under StopImmediately when an inner step fails', function (): void {
    $inner = JobPipeline::make([TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class]);

    $builder = (new PipelineBuilder)
        ->nest($inner)
        ->step(TrackExecutionJobC::class);

    expect(fn () => $builder->run())->toThrow(StepExecutionFailed::class);

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class])
        ->and(TrackExecutionJob::$executionOrder)->not->toContain(TrackExecutionJobB::class)
        ->and(TrackExecutionJob::$executionOrder)->not->toContain(TrackExecutionJobC::class);
});

it('runs compensation for previously completed inner steps under StopAndCompensate', function (): void {
    $inner = JobPipeline::make([
        Step::make(TrackExecutionJobA::class)->withCompensation(CompensateJobA::class),
        Step::make(TrackExecutionJobB::class)->withCompensation(CompensateJobB::class),
        FailingJob::class,
    ]);

    $builder = (new PipelineBuilder)
        ->nest($inner)
        ->onFailure(FailStrategy::StopAndCompensate);

    expect(fn () => $builder->run())->toThrow(StepExecutionFailed::class);

    // Compensations fire in reverse order of completed: B then A.
    expect(CompensateJobB::$executed)->toBe([CompensateJobB::class])
        ->and(CompensateJobA::$executed)->toBe([CompensateJobA::class]);
});

it('continues remaining inner steps and advances the outer position under SkipAndContinue', function (): void {
    $inner = JobPipeline::make([TrackExecutionJobA::class, FailingJob::class, TrackExecutionJobB::class]);

    $builder = (new PipelineBuilder)
        ->nest($inner)
        ->step(TrackExecutionJobC::class)
        ->onFailure(FailStrategy::SkipAndContinue);

    $builder->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

it('fires OUTER beforeEach/afterEach hooks per inner step', function (): void {
    $beforeFired = [];
    $afterFired = [];

    $inner = JobPipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class]);

    (new PipelineBuilder)
        ->beforeEach(function ($step) use (&$beforeFired): void {
            $beforeFired[] = $step->jobClass;
        })
        ->afterEach(function ($step) use (&$afterFired): void {
            $afterFired[] = $step->jobClass;
        })
        ->nest($inner)
        ->step(TrackExecutionJobC::class)
        ->run();

    expect($beforeFired)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ])->and($afterFired)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
});

it('fires pipeline-level onSuccess/onComplete callbacks exactly once at the outer terminal', function (): void {
    $successFires = 0;
    $completeFires = 0;

    $inner = JobPipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class]);

    (new PipelineBuilder)
        ->onSuccess(function () use (&$successFires): void {
            $successFires++;
        })
        ->onComplete(function () use (&$completeFires): void {
            $completeFires++;
        })
        ->nest($inner)
        ->step(TrackExecutionJobC::class)
        ->run();

    expect($successFires)->toBe(1)
        ->and($completeFires)->toBe(1);
});

it('ignores the inner pipelines own failStrategy in favor of the outer wins rule', function (): void {
    // Inner is declared as SkipAndContinue, but the outer strategy defaults to StopImmediately.
    // FailingJob throws inside the inner group → outer StopImmediately rethrows, proving
    // inner failStrategy was ignored.
    $inner = JobPipeline::make([TrackExecutionJobA::class, FailingJob::class])
        ->onFailure(FailStrategy::SkipAndContinue);

    $builder = (new PipelineBuilder)->nest($inner)->step(TrackExecutionJobC::class);

    expect(fn () => $builder->run())->toThrow(StepExecutionFailed::class);
    expect(TrackExecutionJob::$executionOrder)->not->toContain(TrackExecutionJobC::class);
});

it('collapses a double-wrap when an inner step rethrows StepExecutionFailed from a nested pipeline', function (): void {
    // Pins deferred-work.md:25 — when an inner step itself runs another
    // pipeline that threw StepExecutionFailed, the outer executeNestedPipeline
    // should unwrap to the original cause so the outer frame wraps ONCE (not
    // twice).
    $inner = JobPipeline::make([StepExecutionFailedThrowingJob::class]);
    $builder = (new PipelineBuilder)->nest($inner);

    try {
        $builder->run();
        $this->fail('Expected StepExecutionFailed was not thrown.');
    } catch (StepExecutionFailed $outer) {
        expect($outer->getPrevious())
            ->toBeInstanceOf(RuntimeException::class)
            ->and($outer->getPrevious()->getMessage())
            ->toBe('root-cause');
    }
});
