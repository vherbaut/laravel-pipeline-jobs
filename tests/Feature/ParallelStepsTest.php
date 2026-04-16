<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\ParallelStepGroup;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];
});

it('runs sub-steps sequentially in declaration order and context enrichment survives across the group', function (Closure $builderFactory): void {
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
        JobPipeline::parallel([TrackExecutionJobB::class, EnrichContextJob::class]),
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->parallel([TrackExecutionJobB::class, EnrichContextJob::class])
        ->step(TrackExecutionJobC::class),
]);

it('advances the outer position exactly once for a parallel group (flatStepCount expands sub-steps)', function (): void {
    $builder = new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([TrackExecutionJobB::class, TrackExecutionJobC::class]),
    ]);

    $definition = $builder->build();

    expect($definition->stepCount())->toBe(2)
        ->and($definition->flatStepCount())->toBe(3);
});

it('aborts remaining sub-steps under StopImmediately when a sibling fails', function (Closure $builderFactory): void {
    expect(fn () => $builderFactory()->run())
        ->toThrow(StepExecutionFailed::class);

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ])->and(TrackExecutionJob::$executionOrder)->not->toContain(TrackExecutionJobC::class);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([TrackExecutionJobB::class, FailingJob::class, TrackExecutionJobC::class]),
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->parallel([TrackExecutionJobB::class, FailingJob::class, TrackExecutionJobC::class])
        ->step(TrackExecutionJobC::class),
]);

it('runs compensation for previously completed sub-steps under StopAndCompensate', function (Closure $builderFactory): void {
    expect(fn () => $builderFactory()->run())
        ->toThrow(StepExecutionFailed::class);

    // CompensateJobB compensates TrackExecutionJobB (the pre-failure completed sub-step)
    // AND CompensateJobA compensates TrackExecutionJobA. Both fire in reverse order.
    expect(CompensateJobB::$executed)->toBe([CompensateJobB::class])
        ->and(CompensateJobA::$executed)->toBe([CompensateJobA::class]);
})->with([
    'array API' => fn () => (new PipelineBuilder([
        TrackExecutionJobA::class,
    ]))
        ->compensateWith(CompensateJobA::class)
        ->addParallelGroup(ParallelStepGroup::fromArray([
            Step::make(TrackExecutionJobB::class)->withCompensation(CompensateJobB::class),
            Step::make(FailingJob::class),
        ]))
        ->onFailure(FailStrategy::StopAndCompensate),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->compensateWith(CompensateJobA::class)
        ->parallel([
            Step::make(TrackExecutionJobB::class)->withCompensation(CompensateJobB::class),
            Step::make(FailingJob::class),
        ])
        ->onFailure(FailStrategy::StopAndCompensate),
]);

it('continues remaining sub-steps and the rest of the pipeline under SkipAndContinue', function (Closure $builderFactory): void {
    $builderFactory()->run();

    // FailingJob throws but SkipAndContinue logs it and continues. Other sub-steps
    // and the downstream StepC still run.
    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]);
})->with([
    'array API' => fn () => (new PipelineBuilder([
        TrackExecutionJobA::class,
        JobPipeline::parallel([FailingJob::class, TrackExecutionJobB::class]),
        TrackExecutionJobC::class,
    ]))->onFailure(FailStrategy::SkipAndContinue),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->parallel([FailingJob::class, TrackExecutionJobB::class])
        ->step(TrackExecutionJobC::class)
        ->onFailure(FailStrategy::SkipAndContinue),
]);

it('records the failing sub-step class in the thrown StepExecutionFailed message', function (): void {
    try {
        (new PipelineBuilder([
            TrackExecutionJobA::class,
            JobPipeline::parallel([FailingJob::class, TrackExecutionJobB::class]),
        ]))->run();
    } catch (StepExecutionFailed $exception) {
        // The failing sub-step's class (not the group marker) surfaces in the message.
        expect($exception->getMessage())->toContain(FailingJob::class);

        return;
    }

    expect(false)->toBeTrue('Expected StepExecutionFailed to be thrown');
});
