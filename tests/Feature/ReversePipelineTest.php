<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Vherbaut\LaravelPipelineJobs\ConditionalBranch;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\NestedPipeline;
use Vherbaut\LaravelPipelineJobs\ParallelStepGroup;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\CompensateJobC;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\IncrementCountJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobC;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];
    CompensateJobC::$executed = [];
});

// -----------------------------------------------------------------------------
// Task 2 — Sync execution tests for reversed order (AC #3, #9, #11)
// -----------------------------------------------------------------------------

it('sync: executes flat steps in reversed order under run() (AC #3)', function (Closure $builderFactory): void {
    $builderFactory()
        ->send(new SimpleContext)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobC::class,
        TrackExecutionJobB::class,
        TrackExecutionJobA::class,
    ]);
})->with([
    'array API' => fn () => (new PipelineBuilder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]))->reverse(),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(TrackExecutionJobB::class)
        ->step(TrackExecutionJobC::class)
        ->reverse(),
]);

it('sync: context mutations flow through the reversed order and accumulate correctly (AC #3)', function (): void {
    $context = new SimpleContext;

    $result = JobPipeline::make([
        IncrementCountJob::class,
        IncrementCountJob::class,
        IncrementCountJob::class,
    ])
        ->reverse()
        ->send($context)
        ->run();

    expect($result)->toBeInstanceOf(SimpleContext::class)
        ->and($result->count)->toBe(3);
});

it('sync: reversed pipeline without send() uses the null context pass-through (AC #5)', function (): void {
    $result = JobPipeline::make([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ])->reverse()->run();

    expect($result)->toBeNull()
        ->and(TrackExecutionJob::$executionOrder)->toBe([
            TrackExecutionJobB::class,
            TrackExecutionJobA::class,
        ]);
});

it('sync: array-API reverse() and fluent-API reverse() produce the same class-string list (AC #9)', function (): void {
    $arrayBuilt = (new PipelineBuilder([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ]))->reverse()->build();

    $fluentBuilt = (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->step(TrackExecutionJobB::class)
        ->step(TrackExecutionJobC::class)
        ->reverse()
        ->build();

    $arrayClasses = array_map(fn ($step) => $step->jobClass, $arrayBuilt->steps);
    $fluentClasses = array_map(fn ($step) => $step->jobClass, $fluentBuilt->steps);

    expect($arrayClasses)->toBe($fluentClasses)
        ->and($arrayClasses)->toBe([
            TrackExecutionJobC::class,
            TrackExecutionJobB::class,
            TrackExecutionJobA::class,
        ]);
});

it('sync: mixed API make([A, B])->step(C)->reverse() reverses to [C, B, A] (AC #9)', function (): void {
    $built = JobPipeline::make([TrackExecutionJobA::class, TrackExecutionJobB::class])
        ->step(TrackExecutionJobC::class)
        ->reverse()
        ->build();

    $classes = array_map(fn ($step) => $step->jobClass, $built->steps);

    expect($classes)->toBe([
        TrackExecutionJobC::class,
        TrackExecutionJobB::class,
        TrackExecutionJobA::class,
    ]);
});

it('sync: reversed pipeline preserves when() conditions (AC #11)', function (bool $active, array $expectedOrder): void {
    $context = new SimpleContext;
    $context->active = $active;

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->when(fn (SimpleContext $ctx) => $ctx->active, TrackExecutionJobB::class)
        ->step(TrackExecutionJobC::class)
        ->reverse()
        ->send($context)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe($expectedOrder);
})->with([
    'when condition true — B runs at its reversed position' => [
        true,
        [TrackExecutionJobC::class, TrackExecutionJobB::class, TrackExecutionJobA::class],
    ],
    'when condition false — B skipped, pipeline continues' => [
        false,
        [TrackExecutionJobC::class, TrackExecutionJobA::class],
    ],
]);

it('sync: reversed pipeline preserves unless() conditions (AC #11)', function (bool $active, array $expectedOrder): void {
    $context = new SimpleContext;
    $context->active = $active;

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->unless(fn (SimpleContext $ctx) => $ctx->active, TrackExecutionJobB::class)
        ->step(TrackExecutionJobC::class)
        ->reverse()
        ->send($context)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe($expectedOrder);
})->with([
    'unless condition true — B skipped' => [
        true,
        [TrackExecutionJobC::class, TrackExecutionJobA::class],
    ],
    'unless condition false — B runs at its reversed position' => [
        false,
        [TrackExecutionJobC::class, TrackExecutionJobB::class, TrackExecutionJobA::class],
    ],
]);

// -----------------------------------------------------------------------------
// Task 3 — Queued execution tests for reversed order (AC #8)
// -----------------------------------------------------------------------------

it('queued: reverse()->shouldBeQueued() dispatches the first wrapper against the LAST declared step (AC #8)', function (): void {
    Bus::fake();

    JobPipeline::make([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ])
        ->reverse()
        ->shouldBeQueued()
        ->send(new SimpleContext)
        ->run();

    Bus::assertDispatchedTimes(PipelineStepJob::class, 1);
    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->manifest->currentStepIndex === 0
            && $job->manifest->stepClasses === [
                TrackExecutionJobC::class,
                TrackExecutionJobB::class,
                TrackExecutionJobA::class,
            ],
    );
});

it('queued: reversed pipeline self-dispatches the chain in reversed order via sync driver (AC #8)', function (): void {
    config()->set('queue.default', 'sync');

    JobPipeline::make([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ])
        ->reverse()
        ->shouldBeQueued()
        ->send(new SimpleContext)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobC::class,
        TrackExecutionJobB::class,
        TrackExecutionJobA::class,
    ]);
});

it('queued: the dispatched manifest serializes/deserializes with reversed stepClasses intact (AC #8)', function (): void {
    Bus::fake();

    JobPipeline::make([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
    ])
        ->reverse()
        ->shouldBeQueued()
        ->send(new SimpleContext)
        ->run();

    /** @var PipelineStepJob $dispatched */
    $dispatched = Bus::dispatched(PipelineStepJob::class)->first();

    $restored = unserialize(serialize($dispatched));

    expect($restored->manifest->stepClasses)->toBe([
        TrackExecutionJobC::class,
        TrackExecutionJobB::class,
        TrackExecutionJobA::class,
    ]);
});

// -----------------------------------------------------------------------------
// Task 4 — Outer-position-only reversal through parallel / nested / branch (AC #4)
// -----------------------------------------------------------------------------

it('reverses around a parallel group: outer reversed, sub-step order preserved (AC #4)', function (): void {
    $definition = (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->parallel([TrackExecutionJobB::class, IncrementCountJob::class])
        ->step(TrackExecutionJobC::class)
        ->reverse()
        ->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0]->jobClass)->toBe(TrackExecutionJobC::class)
        ->and($definition->steps[1])->toBeInstanceOf(ParallelStepGroup::class)
        ->and($definition->steps[1]->steps[0]->jobClass)->toBe(TrackExecutionJobB::class)
        ->and($definition->steps[1]->steps[1]->jobClass)->toBe(IncrementCountJob::class)
        ->and($definition->steps[2]->jobClass)->toBe(TrackExecutionJobA::class);
});

it('reverses around a nested pipeline: outer reversed, inner definition unchanged (AC #4)', function (): void {
    $inner = JobPipeline::make([TrackExecutionJobB::class, IncrementCountJob::class]);

    $definition = (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->nest($inner)
        ->step(TrackExecutionJobC::class)
        ->reverse()
        ->build();

    expect($definition->steps[0]->jobClass)->toBe(TrackExecutionJobC::class)
        ->and($definition->steps[1])->toBeInstanceOf(NestedPipeline::class)
        ->and($definition->steps[1]->definition->steps[0]->jobClass)->toBe(TrackExecutionJobB::class)
        ->and($definition->steps[1]->definition->steps[1]->jobClass)->toBe(IncrementCountJob::class)
        ->and($definition->steps[2]->jobClass)->toBe(TrackExecutionJobA::class);
});

it('reverses around a conditional branch: outer reversed, branches map unchanged (AC #4)', function (): void {
    $selector = fn (SimpleContext $ctx) => $ctx->name;

    $definition = (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->branch($selector, [
            'x' => TrackExecutionJobB::class,
            'y' => IncrementCountJob::class,
        ])
        ->step(TrackExecutionJobC::class)
        ->reverse()
        ->build();

    expect($definition->steps[0]->jobClass)->toBe(TrackExecutionJobC::class)
        ->and($definition->steps[1])->toBeInstanceOf(ConditionalBranch::class)
        ->and(array_keys($definition->steps[1]->branches))->toBe(['x', 'y'])
        ->and($definition->steps[1]->branches['x']->jobClass)->toBe(TrackExecutionJobB::class)
        ->and($definition->steps[1]->branches['y']->jobClass)->toBe(IncrementCountJob::class)
        ->and($definition->steps[1]->selector)->toBe($selector)
        ->and($definition->steps[2]->jobClass)->toBe(TrackExecutionJobA::class);
});

it('end-to-end reversed sync flow with a parallel group: outer [A, parallel(B, C), TrackExecutionJob] reverses to [TrackExecutionJob, parallel(B, C), A] (AC #4)', function (): void {
    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->parallel([TrackExecutionJobB::class, TrackExecutionJobC::class])
        ->step(TrackExecutionJob::class)
        ->reverse()
        ->send(new SimpleContext)
        ->run();

    // Outer reversal puts TrackExecutionJob (last) at outer position 0 and A (first)
    // at outer position 2. Parallel sub-steps B and C keep their declared inner order.
    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJob::class,
        TrackExecutionJobB::class,
        TrackExecutionJobC::class,
        TrackExecutionJobA::class,
    ]);
});

it('end-to-end reversed sync flow with a nested pipeline: [A, nest(N1, N2), C] reverses to [C, nest(N1, N2), A] (AC #4)', function (): void {
    $inner = JobPipeline::make([TrackExecutionJobB::class, IncrementCountJob::class]);

    (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->nest($inner)
        ->step(TrackExecutionJobC::class)
        ->reverse()
        ->send(new SimpleContext)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobC::class,
        TrackExecutionJobB::class,
        TrackExecutionJobA::class,
    ]);
});

// -----------------------------------------------------------------------------
// Task 5 — Compensation interaction (AC #10)
// -----------------------------------------------------------------------------

it('reversed pipeline under StopAndCompensate compensates following the REVERSED execution order (AC #10)', function (): void {
    // Declared: [A (compA), B (compB), Failing]
    // Reversed: [Failing, B (compB), A (compA)]
    // Failing is at reversed position 0, so it fails first; NO steps completed before it,
    // so NO compensation runs. Assert that to pin the reversed position semantic.
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(FailingJob::class)
        ->reverse()
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(TrackExecutionJob::$executionOrder)->toBe([])
        ->and(CompensateJobA::$executed)->toBe([])
        ->and(CompensateJobB::$executed)->toBe([]);
});

it('reversed pipeline under StopAndCompensate: failing step at reversed position 2 compensates the two reversed-order completed steps (AC #10)', function (): void {
    // Declared: [Failing, B (compB), A (compA)]
    // Reversed: [A (compA), B (compB), Failing]
    // Reversed execution: A runs → B runs → Failing fails at reversed position 2.
    // Completed-in-reverse-order = [B, A] → compensation chain = [compB, compA].
    expect(fn () => (new PipelineBuilder)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->reverse()
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ])
        ->and(CompensateJobB::$executed)->toBe([CompensateJobB::class])
        ->and(CompensateJobA::$executed)->toBe([CompensateJobA::class]);
});

it('parity: forward vs reversed pipeline under StopAndCompensate yields compensation chains ordered by chosen execution order (AC #10)', function (): void {
    // Forward pipeline: [A (compA), B (compB), Failing] — A runs, B runs, Failing fails.
    // Compensation chain under StopAndCompensate = reverse of completed = [compB, compA].
    expect(fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(FailingJob::class)
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    $forwardCompensationChain = [
        ...CompensateJobB::$executed,
        ...CompensateJobA::$executed,
    ];

    // Reset and run the reversed equivalent whose execution order and compensation
    // chain are both the mirror of the forward run.
    TrackExecutionJob::$executionOrder = [];
    CompensateJobA::$executed = [];
    CompensateJobB::$executed = [];

    expect(fn () => (new PipelineBuilder)
        ->step(FailingJob::class)
        ->step(TrackExecutionJobB::class)->compensateWith(CompensateJobB::class)
        ->step(TrackExecutionJobA::class)->compensateWith(CompensateJobA::class)
        ->reverse()
        ->onFailure(FailStrategy::StopAndCompensate)
        ->send(new SimpleContext)
        ->run()
    )->toThrow(StepExecutionFailed::class);

    $reversedCompensationChain = [
        ...CompensateJobB::$executed,
        ...CompensateJobA::$executed,
    ];

    // Both runs produce the same [compB, compA] order because both executions
    // completed [A, B] before failing. The invariant: compensation chain
    // follows the CHOSEN execution order, not the declaration order.
    expect($forwardCompensationChain)->toBe([CompensateJobB::class, CompensateJobA::class])
        ->and($reversedCompensationChain)->toBe([CompensateJobB::class, CompensateJobA::class]);
});
