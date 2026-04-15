<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Exceptions\ContextSerializationFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\Execution\QueuedExecutor;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\NonSerializableContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

function makeQueuedDefinitionAndManifest(?PipelineContext $context = null): array
{
    $definition = new PipelineDefinition(
        steps: [
            StepDefinition::fromJobClass(TrackExecutionJobA::class),
            StepDefinition::fromJobClass(TrackExecutionJobB::class),
        ],
        shouldBeQueued: true,
    );

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class, TrackExecutionJobB::class],
        context: $context,
    );

    return [$definition, $manifest];
}

it('dispatches a PipelineStepJob with the given manifest', function (): void {
    Bus::fake();

    [$definition, $manifest] = makeQueuedDefinitionAndManifest(new SimpleContext);

    (new QueuedExecutor)->execute($definition, $manifest);

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->manifest->currentStepIndex === 0
            && $job->manifest->pipelineId === $manifest->pipelineId,
    );
});

it('returns null when the pipeline is queued', function (): void {
    Bus::fake();

    [$definition, $manifest] = makeQueuedDefinitionAndManifest(new SimpleContext);

    $result = (new QueuedExecutor)->execute($definition, $manifest);

    expect($result)->toBeNull();
});

it('validates the context before dispatching and bubbles ContextSerializationFailed', function (): void {
    Bus::fake();

    $context = new NonSerializableContext;
    $context->callback = fn () => null;

    [$definition, $manifest] = makeQueuedDefinitionAndManifest($context);

    expect(fn () => (new QueuedExecutor)->execute($definition, $manifest))
        ->toThrow(ContextSerializationFailed::class);

    Bus::assertNothingDispatched();
});

it('skips context validation when the context is null', function (): void {
    Bus::fake();

    [$definition, $manifest] = makeQueuedDefinitionAndManifest(null);

    $result = (new QueuedExecutor)->execute($definition, $manifest);

    expect($result)->toBeNull();

    Bus::assertDispatched(PipelineStepJob::class);
});

it('applies onQueue when stepConfigs[0] carries a non-null queue', function (): void {
    Bus::fake();

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass(TrackExecutionJobA::class)],
        shouldBeQueued: true,
    );

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
        context: new SimpleContext,
        stepConfigs: [0 => ['queue' => 'heavy', 'connection' => null, 'sync' => false]],
    );

    (new QueuedExecutor)->execute($definition, $manifest);

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === 'heavy',
    );
});

it('applies both onQueue and onConnection when stepConfigs[0] carries non-null values', function (): void {
    Bus::fake();

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass(TrackExecutionJobA::class)],
        shouldBeQueued: true,
    );

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
        context: new SimpleContext,
        stepConfigs: [0 => ['queue' => 'heavy', 'connection' => 'redis', 'sync' => false]],
    );

    (new QueuedExecutor)->execute($definition, $manifest);

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->queue === 'heavy'
            && $job->connection === 'redis',
    );
});

it('uses dispatch_sync when stepConfigs[0] marks the first step as sync', function (): void {
    Bus::fake();

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass(TrackExecutionJobA::class)],
        shouldBeQueued: true,
    );

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
        context: new SimpleContext,
        stepConfigs: [0 => ['queue' => null, 'connection' => null, 'sync' => true]],
    );

    (new QueuedExecutor)->execute($definition, $manifest);

    Bus::assertDispatchedSync(PipelineStepJob::class);
});

it('applies $timeout on the wrapper when stepConfigs[0] carries a non-null timeout', function (): void {
    Bus::fake();

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass(TrackExecutionJobA::class)],
        shouldBeQueued: true,
    );

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
        context: new SimpleContext,
        stepConfigs: [0 => ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => 90]],
    );

    (new QueuedExecutor)->execute($definition, $manifest);

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->timeout === 90,
    );
});

it('applies $timeout on the sync wrapper when stepConfigs[0] is sync + timeout', function (): void {
    Bus::fake();

    $definition = new PipelineDefinition(
        steps: [StepDefinition::fromJobClass(TrackExecutionJobA::class)],
        shouldBeQueued: true,
    );

    $manifest = PipelineManifest::create(
        stepClasses: [TrackExecutionJobA::class],
        context: new SimpleContext,
        stepConfigs: [0 => ['queue' => null, 'connection' => null, 'sync' => true, 'retry' => null, 'backoff' => null, 'timeout' => 90]],
    );

    (new QueuedExecutor)->execute($definition, $manifest);

    Bus::assertDispatchedSync(
        PipelineStepJob::class,
        fn (PipelineStepJob $job): bool => $job->timeout === 90,
    );
});
