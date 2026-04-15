<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\PendingPipelineDispatch;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
});

it('constructs with a PipelineBuilder', function (): void {
    $wrapper = new PendingPipelineDispatch(new PipelineBuilder([]));

    expect($wrapper)->toBeInstanceOf(PendingPipelineDispatch::class);

    $wrapper->cancel();
});

it('constructs with a FakePipelineBuilder under Pipeline::fake()', function (): void {
    Pipeline::fake();

    $wrapper = new PendingPipelineDispatch(Pipeline::fake()->make([TrackExecutionJobA::class]));

    expect($wrapper)->toBeInstanceOf(PendingPipelineDispatch::class);
});

it('does NOT expose run() on the dispatch wrapper', function (): void {
    expect(method_exists(PendingPipelineDispatch::class, 'run'))->toBeFalse();
});

it('does NOT expose toListener() on the dispatch wrapper', function (): void {
    expect(method_exists(PendingPipelineDispatch::class, 'toListener'))->toBeFalse();
});

it('does NOT expose build() on the dispatch wrapper', function (): void {
    expect(method_exists(PendingPipelineDispatch::class, 'build'))->toBeFalse();
});

it('does NOT expose return() on the dispatch wrapper', function (): void {
    expect(method_exists(PendingPipelineDispatch::class, 'return'))->toBeFalse();
});

it('does NOT expose getContext() on the dispatch wrapper', function (): void {
    expect(method_exists(PendingPipelineDispatch::class, 'getContext'))->toBeFalse();
});

// --- Destructor-timing tests (Task 13, AC #10, AC #11) ---

it('destructor fires at the end of a bare statement', function (): void {
    Pipeline::dispatch([TrackExecutionJobA::class])->send(new SimpleContext);

    // The counter must have incremented before this assertion line, proving
    // the destructor fired at end-of-statement on the line above.
    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);
});

it('destructor fires at scope end for assigned variables', function (): void {
    $flag = false;

    (function () use (&$flag): void {
        $pending = Pipeline::dispatch([TrackExecutionJobA::class])->send(new SimpleContext);
        $flag = true;
        // Closure returns; $pending goes out of scope; destructor fires.
        unset($pending);
    })();

    expect($flag)->toBeTrue()
        ->and(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);
});

it('explicit unset() triggers the destructor immediately', function (): void {
    $pending = Pipeline::dispatch([TrackExecutionJobA::class])->send(new SimpleContext);

    expect(TrackExecutionJob::$executionOrder)->toBe([]);

    unset($pending);

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class]);
});

it('destructor exception propagates out of the dispatch expression', function (): void {
    expect(function (): void {
        Pipeline::dispatch([FailingJob::class])->send(new SimpleContext);
    })->toThrow(StepExecutionFailed::class);
});

it('idempotent hasRun flag prevents the destructor from re-executing', function (): void {
    Pipeline::fake();
    $fake = Pipeline::fake();

    $pending = new PendingPipelineDispatch($fake->make([TrackExecutionJobA::class]));

    // Cancel BEFORE unset so the destructor short-circuits.
    $pending->cancel();

    unset($pending);

    expect($fake->recordedPipelines())->toHaveCount(0);
});
