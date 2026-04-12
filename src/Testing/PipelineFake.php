<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Closure;
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

    /** @var array<int, PipelineDefinition> */
    private array $recordedPipelines = [];

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
     * Record a pipeline definition for later assertion.
     *
     * Called internally by FakePipelineBuilder when run() or toListener()
     * is invoked. Not intended for direct use in tests.
     *
     * @param PipelineDefinition $definition The pipeline definition to record.
     * @return void
     */
    public function recordPipeline(PipelineDefinition $definition): void
    {
        $this->recordedPipelines[] = $definition;
    }

    /**
     * Get all recorded pipeline definitions.
     *
     * @return array<int, PipelineDefinition> The recorded pipeline definitions in dispatch order.
     */
    public function recordedPipelines(): array
    {
        return $this->recordedPipelines;
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
    }
}
