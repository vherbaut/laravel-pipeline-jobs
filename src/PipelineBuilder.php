<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

/**
 * Mutable builder that accumulates pipeline steps and configuration
 * before producing an immutable PipelineDefinition via build().
 */
final class PipelineBuilder
{
    /** @var array<int, StepDefinition> */
    private array $steps = [];

    private PipelineContext|Closure|null $context = null;

    /**
     * Create a new pipeline builder.
     *
     * @param array<int, string> $jobs Fully qualified job class names to add as steps.
     */
    public function __construct(array $jobs = [])
    {
        foreach ($jobs as $jobClass) {
            $this->steps[] = StepDefinition::fromJobClass($jobClass);
        }
    }

    /**
     * Set the context to inject into the pipeline at execution time.
     *
     * Accepts either a PipelineContext instance for immediate use,
     * or a Closure for deferred resolution at execution time.
     *
     * @param PipelineContext|Closure $context The context instance or a closure that produces one.
     */
    public function send(PipelineContext|Closure $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Build an immutable PipelineDefinition from the accumulated steps.
     */
    public function build(): PipelineDefinition
    {
        return new PipelineDefinition(steps: $this->steps);
    }

    /**
     * Get the stored context or closure.
     */
    public function getContext(): PipelineContext|Closure|null
    {
        return $this->context;
    }
}
