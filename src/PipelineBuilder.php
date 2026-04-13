<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
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

    private ?Closure $returnCallback = null;

    private FailStrategy $failStrategy = FailStrategy::StopImmediately;

    /**
     * Create a new pipeline builder.
     *
     * Accepts a mixed list of job class names and pre-built StepDefinition
     * instances. Strings are converted via StepDefinition::fromJobClass();
     * StepDefinition instances (produced by Step::when(), Step::unless(),
     * or Step::make()) are appended as-is. Any other type triggers
     * InvalidPipelineDefinition at construction time so user code does not
     * silently build an invalid pipeline at runtime.
     *
     * The declared item type intentionally widens to mixed because callers
     * may pass untrusted data (e.g., from configuration) and the runtime
     * check exists precisely to catch that case.
     *
     * @param array<int, mixed> $jobs Job class names or pre-built step definitions; each item must be a class-string or a StepDefinition.
     * @return void
     *
     * @throws InvalidPipelineDefinition When an array item is neither a class-string nor a StepDefinition.
     */
    public function __construct(array $jobs = [])
    {
        foreach ($jobs as $job) {
            if (is_string($job)) {
                $this->steps[] = StepDefinition::fromJobClass($job);

                continue;
            }

            if ($job instanceof StepDefinition) {
                $this->steps[] = $job;

                continue;
            }

            throw new InvalidPipelineDefinition(
                'Pipeline definition items must be class-string or StepDefinition instances, got '.get_debug_type($job).'.',
            );
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
     * Append a pre-built StepDefinition to the pipeline.
     *
     * Used internally by the fluent when()/unless() helpers and also
     * exposed publicly so advanced callers can pass hand-crafted
     * StepDefinition instances without going through the string-class
     * shorthand.
     *
     * @param StepDefinition $step The pre-built step to append.
     * @return static
     */
    public function addStep(StepDefinition $step): static
    {
        $this->steps[] = $step;

        return $this;
    }

    /**
     * Append a step that only runs when the condition evaluates to true.
     *
     * The condition is evaluated at runtime (sync and async) against the
     * live PipelineContext immediately before the step would execute.
     *
     * @param Closure(PipelineContext): bool $condition Predicate evaluated against the live PipelineContext.
     * @param string $jobClass Fully qualified class name of the job to execute when the condition holds.
     * @return static
     */
    public function when(Closure $condition, string $jobClass): static
    {
        return $this->addStep(Step::when($condition, $jobClass));
    }

    /**
     * Append a step that runs unless the condition evaluates to true.
     *
     * Inverse of when(): the step executes when the closure returns a
     * falsy value. Same runtime-evaluation semantics.
     *
     * @param Closure(PipelineContext): bool $condition Predicate evaluated against the live PipelineContext.
     * @param string $jobClass Fully qualified class name of the job to execute when the condition is falsy.
     * @return static
     */
    public function unless(Closure $condition, string $jobClass): static
    {
        return $this->addStep(Step::unless($condition, $jobClass));
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
     * Register a closure that transforms the final PipelineContext into the value returned by run().
     *
     * Behaviour:
     * - Sync-only: the closure is applied exclusively in synchronous mode. Queued runs
     *   (shouldBeQueued()) always return null and the closure is silently skipped.
     * - Null context pass-through: when no context was sent, the closure is still invoked
     *   with null as its sole argument. The caller is responsible for handling the null case.
     * - Last-write-wins: calling return() multiple times silently overrides the previous
     *   closure, matching the ergonomics of send() and shouldBeQueued().
     * - Exceptions thrown by the closure propagate verbatim; they are NOT wrapped in
     *   StepExecutionFailed because the closure is not a step.
     *
     * @param Closure(?PipelineContext): mixed $callback Closure applied to the final PipelineContext after sync execution.
     * @return static
     */
    public function return(Closure $callback): static
    {
        $this->returnCallback = $callback;

        return $this;
    }

    /**
     * Configure how the pipeline reacts when a step fails.
     *
     * Behaviour:
     * - FailStrategy::StopAndCompensate: halts execution and runs compensation
     *   jobs in reverse order (runtime wired in Story 5.2).
     * - FailStrategy::SkipAndContinue: logs the failure, skips the failed step
     *   and continues with the next step using the last successful context
     *   (runtime wired in Story 5.3).
     * - FailStrategy::StopImmediately: halts execution without running any
     *   compensation. This is the default when onFailure() is never called
     *   (preserves Epic 1 FR28 behavior).
     *
     * Last-write-wins: calling onFailure() multiple times silently overrides
     * the previous strategy, matching the ergonomics of send(), shouldBeQueued(),
     * and return().
     *
     * @param FailStrategy $strategy The strategy to apply when a step fails.
     * @return static
     */
    public function onFailure(FailStrategy $strategy): static
    {
        $this->failStrategy = $strategy;

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
            failStrategy: $this->failStrategy,
        );
    }

    /**
     * Execute the pipeline and return a value derived from the final context.
     *
     * Builds the pipeline definition, resolves the context (calling the
     * closure if one was provided), creates a manifest, and delegates to
     * SyncExecutor for synchronous execution or QueuedExecutor when
     * shouldBeQueued() has been called. The queued path dispatches the
     * first step and returns null immediately.
     *
     * @return mixed The return-closure result when ->return() was registered, the final PipelineContext (or null) otherwise. Always null in queued mode.
     *
     * @throws InvalidPipelineDefinition When no steps have been defined.
     * @throws StepExecutionFailed When any step throws an exception in sync mode.
     * @throws ContextSerializationFailed When the context contains a non-serializable property in queued mode.
     */
    public function run(): mixed
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
            stepConditions: $this->buildStepConditions($definition),
        );

        if ($definition->shouldBeQueued) {
            // AC #4: queued mode always returns null; return() is sync-only.
            return (new QueuedExecutor)->execute($definition, $manifest);
        }

        $finalContext = (new SyncExecutor)->execute($definition, $manifest);

        if ($this->returnCallback !== null) {
            return ($this->returnCallback)($finalContext);
        }

        return $finalContext;
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

        $stepConditions = $this->buildStepConditions($definition);

        return function (object $event) use ($definition, $contextSource, $shouldBeQueued, $stepConditions): void {
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
                stepConditions: $stepConditions,
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

    /**
     * Build the per-step condition entries for the manifest.
     *
     * Iterates the definition's steps and wraps each non-null condition
     * closure in a SerializableClosure so the manifest survives the queue
     * serialization boundary.
     *
     * @param PipelineDefinition $definition The built pipeline definition.
     *
     * @return array<int, array{closure: SerializableClosure, negated: bool}> Condition entries keyed by step index.
     */
    private function buildStepConditions(PipelineDefinition $definition): array
    {
        $conditions = [];

        foreach ($definition->steps as $index => $step) {
            if ($step->condition === null) {
                continue;
            }

            $conditions[$index] = [
                'closure' => new SerializableClosure($step->condition),
                'negated' => $step->conditionNegated,
            ];
        }

        return $conditions;
    }
}
