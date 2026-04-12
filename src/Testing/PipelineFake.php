<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;

/**
 * Test double for JobPipeline that intercepts and records pipeline executions.
 *
 * Follows the Bus::fake() / Queue::fake() pattern: swaps the JobPipeline
 * singleton in the container so the Pipeline facade resolves to this fake.
 * All pipeline operations (make, listen) are recorded without executing
 * any jobs, allowing assertions on what was dispatched.
 */
class PipelineFake
{
    use PipelineAssertions;

    /** @var array<int, RecordedPipeline> */
    private array $recordedPipelines = [];

    private bool $shouldRecord = false;

    /**
     * Enable recording mode for subsequent pipeline executions.
     *
     * When recording mode is active, pipelines are executed synchronously
     * via RecordingExecutor instead of being silently swallowed. The full
     * execution trace (completed steps, per-step context snapshots) is
     * captured alongside the pipeline definition.
     *
     * @return static
     */
    public function recording(): static
    {
        $this->shouldRecord = true;

        return $this;
    }

    /**
     * Check whether recording mode is active.
     *
     * @return bool True if recording mode is enabled.
     */
    public function isRecording(): bool
    {
        return $this->shouldRecord;
    }

    /**
     * Create a new fake pipeline builder that records instead of executing.
     *
     * Returns a FakePipelineBuilder that mirrors the PipelineBuilder API.
     * When run() or toListener() is called on the returned builder, the
     * pipeline definition is recorded in this fake without executing any jobs.
     *
     * @param array<int, string> $jobs Fully qualified job class names to add as pipeline steps.
     * @return FakePipelineBuilder A builder that records pipeline definitions on execution.
     */
    public function make(array $jobs = []): FakePipelineBuilder
    {
        return new FakePipelineBuilder($this, $jobs);
    }

    /**
     * Record a pipeline listener registration without registering an event listener.
     *
     * Mirrors JobPipeline::listen() but does not call Event::listen().
     * The pipeline definition is built and recorded for assertion.
     *
     * @param string $eventClass Fully qualified event class the pipeline listens to.
     * @param array<int, class-string> $jobs Fully qualified job class names executed in declared order.
     * @param Closure|null $send Optional context resolver.
     * @return void
     */
    public function listen(string $eventClass, array $jobs, ?Closure $send = null): void
    {
        $builder = $this->make($jobs);

        if ($send !== null) {
            $builder->send($send);
        }

        $builder->run();
    }

    /**
     * Record a pipeline execution for later assertion.
     *
     * Called internally by FakePipelineBuilder when run() or toListener()
     * is invoked. Not intended for direct use in tests.
     *
     * @param PipelineDefinition $definition The pipeline definition to record.
     * @param PipelineContext|null $recordedContext The recorded context: sent context in fake mode, final context in recording mode.
     * @param array<int, string> $executedSteps Ordered list of completed step class names (recording mode only).
     * @param array<int, PipelineContext> $contextSnapshots Per-step context snapshots in execution order (recording mode only).
     * @param bool $wasRecording Whether this pipeline was executed in recording mode.
     * @param bool $compensationTriggered Whether compensation was triggered during execution.
     * @param array<int, string> $compensationSteps Ordered list of compensation class names that were executed.
     * @return void
     */
    public function recordPipeline(
        PipelineDefinition $definition,
        ?PipelineContext $recordedContext = null,
        array $executedSteps = [],
        array $contextSnapshots = [],
        bool $wasRecording = false,
        bool $compensationTriggered = false,
        array $compensationSteps = [],
    ): void {
        $this->recordedPipelines[] = new RecordedPipeline(
            definition: $definition,
            recordedContext: $recordedContext,
            executedSteps: $executedSteps,
            contextSnapshots: $contextSnapshots,
            wasRecording: $wasRecording,
            compensationTriggered: $compensationTriggered,
            compensationSteps: $compensationSteps,
        );
    }

    /**
     * Get all recorded pipeline executions.
     *
     * @return array<int, RecordedPipeline> The recorded pipelines in dispatch order.
     */
    public function recordedPipelines(): array
    {
        return $this->recordedPipelines;
    }

    /**
     * Get the recorded context for a specific pipeline execution.
     *
     * Returns the sent context in fake mode, or the final context
     * in recording mode. When no index is provided, returns the
     * context from the most recently recorded pipeline.
     *
     * @param int|null $pipelineIndex 0-based index into the recording order, or null for the most recent.
     * @return PipelineContext|null The recorded context, or null if none was sent.
     */
    public function getRecordedContext(?int $pipelineIndex = null): ?PipelineContext
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        return $recorded->recordedContext;
    }

    /**
     * Clear all recorded pipeline definitions.
     *
     * Resets the fake to a clean state. Useful in beforeEach() blocks
     * to ensure test isolation.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->recordedPipelines = [];
        $this->shouldRecord = false;
    }

    /**
     * Resolve a recorded pipeline by index.
     *
     * When no index is provided, returns the most recently recorded pipeline.
     * Fails with a clear assertion message if the index is out of bounds
     * or no pipelines have been recorded.
     *
     * @param int|null $pipelineIndex 0-based index, or null for the most recent.
     * @return RecordedPipeline The resolved recorded pipeline.
     */
    private function resolveRecordedPipeline(?int $pipelineIndex = null): RecordedPipeline
    {
        $recorded = $this->recordedPipelines();

        Assert::assertNotEmpty(
            $recorded,
            'No pipelines have been recorded. Call Pipeline::make([...])->run() first.',
        );

        if ($pipelineIndex === null) {
            return $recorded[count($recorded) - 1];
        }

        Assert::assertArrayHasKey(
            $pipelineIndex,
            $recorded,
            sprintf(
                'Pipeline index %d is out of bounds. Only %d pipeline(s) were recorded (indices 0-%d).',
                $pipelineIndex,
                count($recorded),
                count($recorded) - 1,
            ),
        );

        return $recorded[$pipelineIndex];
    }
}
