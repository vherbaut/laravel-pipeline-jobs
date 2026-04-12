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
