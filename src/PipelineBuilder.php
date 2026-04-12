<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Exceptions\ContextSerializationFailed;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\QueuedExecutor;
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

    private bool $shouldBeQueued = false;

    /**
     * Create a new pipeline builder.
     *
     * @param array<int, string> $jobs Fully qualified job class names to add as steps.
     * @return void
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
     * @return static
     */
    public function step(string $jobClass): static
    {
        $this->steps[] = StepDefinition::fromJobClass($jobClass);

        return $this;
    }

    /**
     * Assign a compensation job to the last added step for saga rollback.
     *
     * Replaces the last StepDefinition with a new instance that carries
     * the given compensation class. All other properties of the original
     * step are preserved. Must be called after at least one step() or
     * constructor-provided job class.
     *
     * @param string $compensationClass Fully qualified class name of the compensation job.
     * @return static
     *
     * @throws InvalidPipelineDefinition When no steps have been added yet or compensation is already defined.
     */
    public function compensateWith(string $compensationClass): static
    {
        if ($this->steps === []) {
            throw new InvalidPipelineDefinition('Cannot call compensateWith() before adding a step.');
        }

        $lastStep = array_pop($this->steps);

        if ($lastStep->compensationJobClass !== null) {
            throw new InvalidPipelineDefinition('Compensation is already defined for the last step.');
        }
        $this->steps[] = new StepDefinition(
            jobClass: $lastStep->jobClass,
            compensationJobClass: $compensationClass,
            condition: $lastStep->condition,
            conditionNegated: $lastStep->conditionNegated,
            queue: $lastStep->queue,
            connection: $lastStep->connection,
            retry: $lastStep->retry,
            backoff: $lastStep->backoff,
            timeout: $lastStep->timeout,
            sync: $lastStep->sync,
        );

        return $this;
    }

    /**
     * Set the context to inject into the pipeline at execution time.
     *
     * Accepts either a PipelineContext instance for immediate use,
     * or a Closure for deferred resolution at execution time.
     *
     * @param PipelineContext|Closure $context The context instance or a closure that produces one.
     * @return static
     */
    public function send(PipelineContext|Closure $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Mark the pipeline as asynchronous so steps are dispatched to the queue.
     *
     * When set, run() delegates to QueuedExecutor which dispatches the first
     * step wrapped in a PipelineStepJob. Each job self-dispatches the next
     * step until the pipeline is complete. Calling this method multiple times
     * is idempotent.
     *
     * @return static
     */
    public function shouldBeQueued(): static
    {
        $this->shouldBeQueued = true;

        return $this;
    }

    /**
     * Build an immutable PipelineDefinition from the accumulated steps.
     *
     * @return PipelineDefinition The immutable pipeline description ready for execution.
     *
     * @throws InvalidPipelineDefinition When the steps array is empty.
     */
    public function build(): PipelineDefinition
    {
        return new PipelineDefinition(
            steps: $this->steps,
            shouldBeQueued: $this->shouldBeQueued,
        );
    }

    /**
     * Execute the pipeline and return the resulting context, or null when queued.
     *
     * Builds the pipeline definition, resolves the context (calling the
     * closure if one was provided), creates a manifest, and delegates to
     * SyncExecutor for synchronous execution or QueuedExecutor when
     * shouldBeQueued() has been called. The queued path dispatches the
     * first step and returns null immediately.
     *
     * @return PipelineContext|null The final pipeline context after sync execution, or null when queued or when no context was provided.
     *
     * @throws InvalidPipelineDefinition When no steps have been defined.
     * @throws StepExecutionFailed When any step throws an exception in sync mode.
     * @throws ContextSerializationFailed When the context contains a non-serializable property in queued mode.
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
            compensationMapping: $definition->compensationMapping(),
        );

        if ($definition->shouldBeQueued) {
            return (new QueuedExecutor)->execute($definition, $manifest);
        }

        return (new SyncExecutor)->execute($definition, $manifest);
    }

    /**
     * Convert this pipeline into a Laravel event listener closure.
     *
     * The returned Closure accepts the event instance dispatched by
     * Laravel's event bus. When invoked, it resolves the pipeline context
     * by calling the ->send() closure with the event (or using the stored
     * PipelineContext instance if one was set), builds a fresh
     * PipelineManifest, and executes the pipeline via SyncExecutor or
     * QueuedExecutor depending on ->shouldBeQueued().
     *
     * The pipeline definition, context source, and queued flag are
     * captured eagerly at toListener() time. Subsequent mutations to the
     * builder do NOT affect previously returned closures.
     *
     * Context sharing note: when ->send(new PipelineContext) is used
     * (instance form), the returned closure captures the same instance
     * by reference. Mutations performed by pipeline steps on that
     * context will persist across subsequent event dispatches, which
     * is usually not desired in a listener scenario where the same
     * closure fires on every event. Prefer the closure form
     * ->send(fn ($event) => new Ctx(...)) to obtain a fresh context
     * per event dispatch.
     *
     * @return Closure(object): void A listener closure that executes the pipeline for each event.
     *
     * @throws InvalidPipelineDefinition When the builder has no steps.
     */
    public function toListener(): Closure
    {
        $definition = $this->build();
        $contextSource = $this->context;
        $shouldBeQueued = $this->shouldBeQueued;

        return function (object $event) use ($definition, $contextSource, $shouldBeQueued): void {
            $resolvedContext = $contextSource instanceof Closure
                ? ($contextSource)($event)
                : $contextSource;

            $stepClasses = array_map(
                fn (StepDefinition $step): string => $step->jobClass,
                $definition->steps,
            );

            $manifest = PipelineManifest::create(
                stepClasses: $stepClasses,
                context: $resolvedContext,
                compensationMapping: $definition->compensationMapping(),
            );

            if ($shouldBeQueued) {
                (new QueuedExecutor)->execute($definition, $manifest);

                return;
            }

            (new SyncExecutor)->execute($definition, $manifest);
        };
    }

    /**
     * Get the stored context or closure.
     *
     * @return PipelineContext|Closure|null The stored context, the deferred closure, or null if none has been set.
     */
    public function getContext(): PipelineContext|Closure|null
    {
        return $this->context;
    }
}
