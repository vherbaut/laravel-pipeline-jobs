<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;

/**
 * A pipeline builder decorator that records pipeline definitions
 * instead of executing them.
 *
 * Used internally by PipelineFake to intercept pipeline execution.
 * Delegates step/send/shouldBeQueued configuration to a real
 * PipelineBuilder, but overrides run() and toListener() to record
 * the built PipelineDefinition without dispatching any jobs.
 */
final class FakePipelineBuilder
{
    private PipelineBuilder $builder;

    /**
     * Create a new fake pipeline builder.
     *
     * @param PipelineFake $fake The fake instance that records pipeline executions.
     * @param array<int, string> $jobs Fully qualified job class names to add as steps.
     */
    public function __construct(
        private readonly PipelineFake $fake,
        array $jobs = [],
    ) {
        $this->builder = new PipelineBuilder($jobs);
    }

    /**
     * Append a single step to the pipeline using a job class name.
     *
     * @param string $jobClass Fully qualified class name of the job to execute.
     * @return static
     */
    public function step(string $jobClass): static
    {
        $this->builder->step($jobClass);

        return $this;
    }

    /**
     * Set the context to inject into the pipeline at execution time.
     *
     * @param PipelineContext|Closure $context The context instance or a closure that produces one.
     * @return static
     */
    public function send(PipelineContext|Closure $context): static
    {
        $this->builder->send($context);

        return $this;
    }

    /**
     * Mark the pipeline as asynchronous.
     *
     * @return static
     */
    public function shouldBeQueued(): static
    {
        $this->builder->shouldBeQueued();

        return $this;
    }

    /**
     * Build an immutable PipelineDefinition from the accumulated steps.
     *
     * @return PipelineDefinition The immutable pipeline description.
     *
     * @throws InvalidPipelineDefinition When the steps array is empty.
     */
    public function build(): PipelineDefinition
    {
        return $this->builder->build();
    }

    /**
     * Record the pipeline definition without executing any jobs.
     *
     * Builds the PipelineDefinition and stores it in the PipelineFake
     * for later assertion. No job handle() method is ever called.
     *
     * @return null Always returns null since no execution occurs.
     */
    public function run(): null
    {
        $definition = $this->builder->build();
        $this->fake->recordPipeline($definition);

        return null;
    }

    /**
     * Record the pipeline definition and return a no-op listener closure.
     *
     * Builds the PipelineDefinition, stores it in the PipelineFake,
     * and returns a closure that does nothing when invoked.
     *
     * @return Closure(object): void A no-op listener closure.
     *
     * @throws InvalidPipelineDefinition When the builder has no steps.
     */
    public function toListener(): Closure
    {
        $definition = $this->builder->build();
        $this->fake->recordPipeline($definition);

        return function (object $event): void {};
    }

    /**
     * Get the stored context or closure from the underlying builder.
     *
     * @return PipelineContext|Closure|null The stored context, the deferred closure, or null if none has been set.
     */
    public function getContext(): PipelineContext|Closure|null
    {
        return $this->builder->getContext();
    }
}
