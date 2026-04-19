<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Vherbaut\LaravelPipelineJobs\ConcurrencyPolicy;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineConcurrencyLimitExceeded;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\PipelineConcurrencyGate;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

beforeEach(function (): void {
    Cache::flush();
});

it('acquire returns null and performs no Cache calls when policy is null (zero-overhead fast path)', function (): void {
    Cache::spy();

    $key = PipelineConcurrencyGate::acquire(null, null);

    expect($key)->toBeNull();
    Cache::shouldNotHaveReceived('add');
    Cache::shouldNotHaveReceived('increment');
    Cache::shouldNotHaveReceived('decrement');
});

it('release performs no Cache calls when key is null', function (): void {
    Cache::spy();

    PipelineConcurrencyGate::release(null);

    Cache::shouldNotHaveReceived('decrement');
});

it('acquire seeds the counter and returns the namespaced cache key on admission', function (): void {
    $policy = new ConcurrencyPolicy('tenant:42', 3);

    $key = PipelineConcurrencyGate::acquire($policy, null);

    expect($key)->toBe('pipeline:concurrent:tenant:42')
        ->and(Cache::get('pipeline:concurrent:tenant:42'))->toBe(1);
});

it('acquire admits up to limit then throws PipelineConcurrencyLimitExceeded with rollback', function (): void {
    $policy = new ConcurrencyPolicy('tenant:42', 2);

    PipelineConcurrencyGate::acquire($policy, null);
    PipelineConcurrencyGate::acquire($policy, null);

    try {
        PipelineConcurrencyGate::acquire($policy, null);
        $this->fail('expected PipelineConcurrencyLimitExceeded');
    } catch (PipelineConcurrencyLimitExceeded $exception) {
        expect($exception->key)->toBe('tenant:42')
            ->and($exception->limit)->toBe(2)
            ->and(Cache::get('pipeline:concurrent:tenant:42'))->toBe(2);
    }
});

it('release decrements the counter for an existing key', function (): void {
    $policy = new ConcurrencyPolicy('tenant:42', 5);
    $key = PipelineConcurrencyGate::acquire($policy, null);
    PipelineConcurrencyGate::acquire($policy, null);

    PipelineConcurrencyGate::release($key);

    expect(Cache::get('pipeline:concurrent:tenant:42'))->toBe(1);
});

it('cacheKey namespaces the resolved key under the pipeline:concurrent: prefix', function (): void {
    expect(PipelineConcurrencyGate::cacheKey('tenant:42'))->toBe('pipeline:concurrent:tenant:42')
        ->and(PipelineConcurrencyGate::cacheKey('user:bob'))->toBe('pipeline:concurrent:user:bob');
});

// Story 9.3 AC #19 — Forbidden release-location pinning guard.
// Asserts PipelineConcurrencyGate::release() is invoked ONLY from approved
// call-sites. The check is a substring scan over source files, sufficient as
// a regression-pinning guard (not a runtime mechanism).
it('PipelineConcurrencyGate::release is invoked ONLY from approved call-sites', function (): void {
    $repoRoot = dirname(__DIR__, 4);
    $forbidden = [
        'src/Execution/CompensationStepJob.php',
        'src/Execution/ParallelStepJob.php',
        'src/Execution/QueuedExecutor.php',
        'src/Execution/SyncExecutor.php',
        'src/Execution/PipelineExecutor.php',
        'src/Execution/Shared/CompensationInvoker.php',
        'src/Execution/Shared/StepConditionEvaluator.php',
        'src/Execution/Shared/StepInvoker.php',
        'src/Execution/Shared/PipelineEventDispatcher.php',
        'src/Execution/Queued/QueuedCompensationDispatcher.php',
        'src/Execution/Queued/QueuedConditionalBranchHandler.php',
        'src/Execution/Queued/QueuedParallelBatchCoordinator.php',
        'src/Testing/RecordingExecutor.php',
        'src/Testing/RecordedPipeline.php',
        'src/Testing/PipelineFake.php',
        'src/Testing/PipelineAssertions.php',
    ];
    foreach ($forbidden as $relative) {
        $path = $repoRoot.DIRECTORY_SEPARATOR.$relative;
        if (! is_file($path)) {
            continue;
        }
        $contents = file_get_contents($path);
        expect(str_contains($contents, 'PipelineConcurrencyGate::release'))
            ->toBeFalse("release() must NOT appear in {$relative}");
    }

    $approved = [
        'src/PipelineBuilder.php',
        'src/Execution/PipelineStepJob.php',
        'src/Testing/FakePipelineBuilder.php',
    ];
    foreach ($approved as $relative) {
        $path = $repoRoot.DIRECTORY_SEPARATOR.$relative;
        $contents = file_get_contents($path);
        expect(str_contains($contents, 'PipelineConcurrencyGate::release'))
            ->toBeTrue("release() must appear in {$relative}");
    }
});

it('acquire resolves the closure key against the live PipelineContext', function (): void {
    $context = new SimpleContext;
    $context->name = 'alice';

    $policy = new ConcurrencyPolicy(static fn (?PipelineContext $ctx): string => 'user:'.($ctx->name ?? 'anon'), 5);

    $key = PipelineConcurrencyGate::acquire($policy, $context);

    expect($key)->toBe('pipeline:concurrent:user:alice')
        ->and(Cache::get('pipeline:concurrent:user:alice'))->toBe(1);
});
