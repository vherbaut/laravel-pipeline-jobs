<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FailingJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ManifestObserverJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

beforeEach(function (): void {
    TrackExecutionJob::$executionOrder = [];
    ReadContextJob::$readName = null;
    ManifestObserverJob::$observedManifest = null;
});

it('resolves the current step, runs it, marks it completed, and advances the index', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, TrackExecutionJobB::class],
    );

    (new PipelineStepJob($manifest))->handle();

    expect(TrackExecutionJob::$executionOrder)->toBe([TrackExecutionJobA::class])
        ->and($manifest->completedSteps)->toBe([TrackExecutionJobA::class])
        ->and($manifest->currentStepIndex)->toBe(1);
});

it('injects the manifest into the step via the pipelineManifest property', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [ManifestObserverJob::class],
    );

    (new PipelineStepJob($manifest))->handle();

    expect(ManifestObserverJob::$observedManifest)->toBe($manifest);
});

it('dispatches the next PipelineStepJob when more steps remain', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, TrackExecutionJobB::class],
    );

    (new PipelineStepJob($manifest))->handle();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->manifest->currentStepIndex === 1,
    );
    Bus::assertDispatchedTimes(PipelineStepJob::class, 1);
});

it('does not dispatch a next job when the current step is the last', function (): void {
    Bus::fake();

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
    );

    (new PipelineStepJob($manifest))->handle();

    Bus::assertNothingDispatched();
});

it('rethrows the step exception and does not dispatch a next job', function (): void {
    Bus::fake();
    Log::spy();

    $manifest = PipelineManifest::create(
        stepClasses: [FailingJob::class, TrackExecutionJobB::class],
    );

    expect(fn () => (new PipelineStepJob($manifest))->handle())
        ->toThrow(RuntimeException::class, 'Job failed intentionally');

    Bus::assertNothingDispatched();
    expect($manifest->completedSteps)->toBe([])
        ->and($manifest->currentStepIndex)->toBe(0);
});

it('logs pipeline context on failure', function (): void {
    Bus::fake();

    $logged = [];
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use (&$logged): bool {
            $logged = $context;

            return $message === 'Pipeline step failed';
        });

    $manifest = PipelineManifest::create(
        stepClasses: [FailingJob::class],
    );

    try {
        (new PipelineStepJob($manifest))->handle();
    } catch (RuntimeException $exception) {
        // expected
    }

    expect($logged)->toHaveKeys(['pipelineId', 'currentStepIndex', 'stepClass', 'exception'])
        ->and($logged['pipelineId'])->toBe($manifest->pipelineId)
        ->and($logged['currentStepIndex'])->toBe(0)
        ->and($logged['stepClass'])->toBe(FailingJob::class)
        ->and($logged['exception'])->toBe('Job failed intentionally');
});

it('passes the mutated PipelineContext forward between two consecutive handle() calls', function (): void {
    Bus::fake();

    $context = new SimpleContext;
    $context->name = 'original';

    $manifest = PipelineManifest::create(
        stepClasses: [EnrichContextJob::class, ReadContextJob::class],
        context: $context,
    );

    // First call: enrich context, advances index to 1, dispatches next job (faked).
    (new PipelineStepJob($manifest))->handle();

    expect($manifest->context)->toBe($context)
        ->and($context->name)->toBe('enriched')
        ->and($manifest->currentStepIndex)->toBe(1);

    // Second call simulates the next worker picking up the dispatched job.
    (new PipelineStepJob($manifest))->handle();

    expect(ReadContextJob::$readName)->toBe('enriched')
        ->and($manifest->currentStepIndex)->toBe(2);
});

it('implements ShouldQueue and uses the required Laravel traits', function (): void {
    $reflection = new ReflectionClass(PipelineStepJob::class);

    expect($reflection->implementsInterface(ShouldQueue::class))->toBeTrue();

    $traits = $reflection->getTraitNames();

    expect($traits)->toContain(Dispatchable::class)
        ->toContain(InteractsWithQueue::class)
        ->toContain(Queueable::class)
        ->toContain(SerializesModels::class);
});
