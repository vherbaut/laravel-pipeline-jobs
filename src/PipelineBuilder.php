<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\SyncExecutor;

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
     * Append a single step to the pipeline using a job class name.
     *
     * @param string $jobClass Fully qualified class name of the job to execute.
     */
    public function step(string $jobClass): static
    {
        $this->steps[] = StepDefinition::fromJobClass($jobClass);

        return $this;
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
     * Execute the pipeline synchronously and return the resulting context.
     *
     * Builds the pipeline definition, resolves the context (calling the
     * closure if one was provided), creates a manifest, and runs all
     * steps sequentially via SyncExecutor.
     *
     * @throws InvalidPipelineDefinition When no steps have been defined.
     * @throws StepExecutionFailed When any step throws an exception.
     */
    public function run(): ?PipelineContext
    {
        $definition = $this->build();

        $resolvedContext = $this->context instanceof Closure
            ? ($this->context)()
            : $this->context;

        $stepClasses = array_map(
            fn (StepDefinition $step): string => $step->jobClass,
            $definition->steps,
        );

        $manifest = PipelineManifest::create(
            stepClasses: $stepClasses,
            context: $resolvedContext,
        );

        return (new SyncExecutor)->execute($definition, $manifest);
    }

    /**
     * Get the stored context or closure.
     */
    public function getContext(): PipelineContext|Closure|null
    {
        return $this->context;
    }
}
