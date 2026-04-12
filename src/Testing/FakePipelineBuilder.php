<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

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
     * Assign a compensation job to the last added step for saga rollback.
     *
     * Delegates to the underlying PipelineBuilder. Must be called after
     * at least one step() or constructor-provided job class.
     *
     * @param string $compensationClass Fully qualified class name of the compensation job.
     * @return static
     *
     * @throws InvalidPipelineDefinition When no steps have been added yet.
     */
    public function compensateWith(string $compensationClass): static
    {
        $this->builder->compensateWith($compensationClass);

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
     * Record the pipeline definition, optionally executing it in recording mode.
     *
     * In default fake mode, builds the PipelineDefinition, resolves the sent
     * context, and stores both without executing any jobs.
     *
     * In recording mode (Pipeline::fake()->recording()), executes the pipeline
     * synchronously via RecordingExecutor, capturing per-step context snapshots
     * and the list of completed steps alongside the definition.
     *
     * @return PipelineContext|null The final context in recording mode, or null in fake mode.
     */
    public function run(): ?PipelineContext
    {
        $definition = $this->builder->build();
        $resolvedContext = $this->resolveContext();

        if ($this->fake->isRecording()) {
            return $this->executeWithRecording($definition, $resolvedContext);
        }

        $this->fake->recordPipeline($definition, $resolvedContext);

        return null;
    }

    /**
     * Record the pipeline definition and return a no-op listener closure.
     *
     * Builds the PipelineDefinition, resolves the sent context, stores
     * both in the PipelineFake, and returns a closure that does nothing
     * when invoked.
     *
     * @return Closure(object): void A no-op listener closure.
     *
     * @throws InvalidPipelineDefinition When the builder has no steps.
     */
    public function toListener(): Closure
    {
        $definition = $this->builder->build();
        $resolvedContext = $this->resolveContext();
        $this->fake->recordPipeline($definition, $resolvedContext);

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

    /**
     * Execute the pipeline via RecordingExecutor and record the full trace.
     *
     * Creates a PipelineManifest, runs all steps synchronously, captures
     * per-step context snapshots and completed steps, then records
     * everything in the PipelineFake.
     *
     * @param PipelineDefinition $definition The built pipeline definition.
     * @param PipelineContext|null $resolvedContext The resolved context to send.
     * @return PipelineContext|null The final context after execution.
     */
    private function executeWithRecording(PipelineDefinition $definition, ?PipelineContext $resolvedContext): ?PipelineContext
    {
        $stepClasses = array_map(
            fn (StepDefinition $step): string => $step->jobClass,
            $definition->steps,
        );

        $compensationMapping = [];
        foreach ($definition->steps as $step) {
            if ($step->compensationJobClass !== null) {
                $compensationMapping[$step->jobClass] = $step->compensationJobClass;
            }
        }

        $manifest = PipelineManifest::create(
            stepClasses: $stepClasses,
            context: $resolvedContext,
            compensationMapping: $compensationMapping,
        );

        $executor = new RecordingExecutor;

        try {
            $finalContext = $executor->execute($definition, $manifest);

            $this->fake->recordPipeline(
                definition: $definition,
                recordedContext: $finalContext,
                executedSteps: $executor->executedSteps(),
                contextSnapshots: $executor->contextSnapshots(),
                wasRecording: true,
            );

            return $finalContext;
        } catch (StepExecutionFailed) {
            $this->fake->recordPipeline(
                definition: $definition,
                recordedContext: $manifest->context,
                executedSteps: $executor->executedSteps(),
                contextSnapshots: $executor->contextSnapshots(),
                wasRecording: true,
                compensationTriggered: $executor->compensationTriggered(),
                compensationSteps: $executor->compensationSteps(),
            );

            return null;
        }
    }

    /**
     * Resolve the stored context for recording.
     *
     * If the context is a Closure, it is called with no arguments.
     * If it is a PipelineContext instance, it is returned as-is.
     * If null, null is returned.
     *
     * @return PipelineContext|null The resolved context, or null if none was set.
     *
     * @throws \InvalidArgumentException When a closure returns a non-null, non-PipelineContext value.
     */
    private function resolveContext(): ?PipelineContext
    {
        $context = $this->builder->getContext();

        if ($context instanceof Closure) {
            $resolved = ($context)();

            if ($resolved === null) {
                return null;
            }

            if (! $resolved instanceof PipelineContext) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'The context closure must return a PipelineContext or null, got %s.',
                        get_debug_type($resolved),
                    ),
                );
            }

            return $resolved;
        }

        return $context;
    }
}
