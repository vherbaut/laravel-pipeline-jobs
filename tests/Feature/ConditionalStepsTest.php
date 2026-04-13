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
    TrackExecutionJob::$executionOrder = [];
});

// --- AC #1: when() step executes when condition is true ---

it('executes a when() step when the condition is true', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->active = true;

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
        Step::when(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class),
        TrackExecutionJobC::class,
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->when(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class)
        ->step(TrackExecutionJobC::class),
]);

// --- AC #2: when() step is skipped when condition is false; pipeline continues ---

it('skips a when() step when the condition is false and continues', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->active = false;

    $builderFactory()
        ->send($context)
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

// --- AC #3: unless() executes when condition is false ---

it('executes an unless() step when the condition is false', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->active = false;

    $builderFactory()
        ->send($context)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
        TrackExecutionJobB::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::unless(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class),
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->unless(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class),
]);

// --- AC #4: unless() is skipped when condition is true ---

it('skips an unless() step when the condition is true', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->active = true;

    $builderFactory()
        ->send($context)
        ->run();

    expect(TrackExecutionJob::$executionOrder)->toBe([
        TrackExecutionJobA::class,
    ]);
})->with([
    'array API' => fn () => new PipelineBuilder([
        TrackExecutionJobA::class,
        Step::unless(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class),
    ]),
    'fluent API' => fn () => (new PipelineBuilder)
        ->step(TrackExecutionJobA::class)
        ->unless(fn (SimpleContext $c) => $c->active, TrackExecutionJobB::class),
]);

// --- AC #5: runtime evaluation, post-step mutation observable by later condition ---

it('evaluates conditions at runtime using context mutated by earlier steps', function (Closure $builderFactory): void {
    $context = new SimpleContext;
    $context->active = false;

    $builderFactory()
        ->send($context)
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
