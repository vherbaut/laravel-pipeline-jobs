<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
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

    private ?Closure $returnCallback = null;

    /**
     * Create a new fake pipeline builder.
     *
     * Widens the $jobs element type to `mixed` so the runtime guard in
     * PipelineBuilder::__construct (throwing InvalidPipelineDefinition for
     * entries that are neither string nor StepDefinition) stays reachable
     * under PHPStan level 5, matching the PipelineBuilder constructor shape.
     *
     * @param PipelineFake $fake The fake instance that records pipeline executions.
     * @param array<int, mixed> $jobs Job class names or pre-built step definitions to add as steps.
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
     * Append a pre-built StepDefinition to the pipeline.
     *
     * @param StepDefinition $step The pre-built step to append.
     * @return static
     */
    public function addStep(StepDefinition $step): static
    {
        $this->builder->addStep($step);

        return $this;
    }

    /**
     * Append a step that only runs when the condition evaluates to true.
     *
     * @param Closure(PipelineContext): bool $condition Predicate evaluated against the live PipelineContext.
     * @param string $jobClass Fully qualified class name of the job to execute when the condition holds.
     * @return static
     */
    public function when(Closure $condition, string $jobClass): static
    {
        $this->builder->when($condition, $jobClass);

        return $this;
    }

    /**
     * Append a step that runs unless the condition evaluates to true.
     *
     * @param Closure(PipelineContext): bool $condition Predicate evaluated against the live PipelineContext.
     * @param string $jobClass Fully qualified class name of the job to execute when the condition is falsy.
     * @return static
     */
    public function unless(Closure $condition, string $jobClass): static
    {
        $this->builder->unless($condition, $jobClass);

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
     * Register a closure that transforms the final PipelineContext into the value returned by run().
     *
     * Mirrors PipelineBuilder::return() with identical semantics (sync-only,
     * null pass-through, last-write-wins, no exception wrapping) but applies
     * only in recording mode. In Pipeline::fake() default (non-recording)
     * mode, run() always returns null and the closure is silently skipped
     * because no steps actually execute.
     *
     * @param Closure(?PipelineContext): mixed $callback Closure applied to the final PipelineContext in recording mode.
     * @return static
     */
    public function return(Closure $callback): static
    {
        $this->returnCallback = $callback;
        $this->builder->return($callback);

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
     * In both fake and recording modes of Pipeline::fake(), the strategy is
     * stored on the recorded PipelineDefinition for later inspection in
     * assertions.
     *
     * @param FailStrategy $strategy The strategy to apply when a step fails.
     * @return static
     */
    public function onFailure(FailStrategy $strategy): static
    {
        $this->builder->onFailure($strategy);

        return $this;
    }

    /**
     * Register a closure invoked before each non-skipped step executes.
     *
     * Delegates to the underlying PipelineBuilder so registered hooks are
     * carried on the built PipelineDefinition and observable via the
     * recorded pipeline in Pipeline::fake() mode. Hooks only fire when the
     * pipeline actually executes: Pipeline::fake()->recording() runs steps
     * through RecordingExecutor which fires hooks identically to
     * SyncExecutor, while Pipeline::fake() default (non-recording) mode
     * records the definition without executing steps, so no hooks fire
     * (step-granular hooks require step execution).
     *
     * Append-semantic like PipelineBuilder::beforeEach(); multiple
     * registrations accumulate in registration order.
     *
     * @param Closure(StepDefinition, ?PipelineContext): void $hook Closure invoked before each non-skipped step.
     * @return static
     */
    public function beforeEach(Closure $hook): static
    {
        $this->builder->beforeEach($hook);

        return $this;
    }

    /**
     * Register a closure invoked after each successful step.
     *
     * Delegates to the underlying PipelineBuilder. Same recording-mode
     * semantics as beforeEach().
     *
     * @param Closure(StepDefinition, ?PipelineContext): void $hook Closure invoked after each successful step.
     * @return static
     */
    public function afterEach(Closure $hook): static
    {
        $this->builder->afterEach($hook);

        return $this;
    }

    /**
     * Register a closure invoked when a step (or another hook) throws.
     *
     * Delegates to the underlying PipelineBuilder. Same recording-mode
     * semantics as beforeEach(). Distinct from onFailure(FailStrategy):
     * onStepFailed() is an append-semantic observability hook,
     * onFailure(FailStrategy) is a last-write-wins strategy setter.
     *
     * @param Closure(StepDefinition, ?PipelineContext, \Throwable): void $hook Closure invoked on step failure.
     * @return static
     */
    public function onStepFailed(Closure $hook): static
    {
        $this->builder->onStepFailed($hook);

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
     * context, and stores both without executing any jobs. No return closure
     * is invoked because no steps actually ran.
     *
     * In recording mode (Pipeline::fake()->recording()), executes the pipeline
     * synchronously via RecordingExecutor, capturing per-step context snapshots
     * and the list of completed steps alongside the definition. The registered
     * return closure (if any) is applied to the final context and its result
     * is returned, matching PipelineBuilder::run() semantics. When a step
     * fails, compensation metadata is recorded and run() returns null; the
     * return closure is NOT invoked, mirroring the real builder's contract
     * (callbacks never see aborted runs).
     *
     * @return mixed The return-closure result when ->return() is registered AND recording mode completed successfully; the final PipelineContext (or null) in recording mode without a callback; always null in fake (non-recording) mode or when a step fails in recording mode.
     */
    public function run(): mixed
    {
        $definition = $this->builder->build();
        $resolvedContext = $this->resolveContext();

        if ($this->fake->isRecording()) {
            try {
                $finalContext = $this->executeWithRecording($definition, $resolvedContext);
            } catch (StepExecutionFailed) {
                // Parity with PipelineBuilder::run(): step failure aborts before ->return() fires.
                return null;
            }

            if ($this->returnCallback !== null) {
                return ($this->returnCallback)($finalContext);
            }

            return $finalContext;
        }

        $this->fake->recordPipeline($definition, $resolvedContext);

        // AC #9: fake mode always returns null; return() applies in recording mode only.
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
     * everything in the PipelineFake. Records compensation metadata when
     * a step fails, then re-throws StepExecutionFailed to preserve parity
     * with PipelineBuilder::run() so ->return() callbacks are not invoked
     * against an aborted run.
     *
     * @param PipelineDefinition $definition The built pipeline definition.
     * @param PipelineContext|null $resolvedContext The resolved context to send.
     * @return PipelineContext|null The final context after successful execution.
     *
     * @throws StepExecutionFailed When any step fails; the failure is recorded first.
     */
    private function executeWithRecording(PipelineDefinition $definition, ?PipelineContext $resolvedContext): ?PipelineContext
    {
        $stepClasses = array_map(
            fn (StepDefinition $step): string => $step->jobClass,
            $definition->steps,
        );

        $stepConditions = [];

        foreach ($definition->steps as $index => $step) {
            if ($step->condition === null) {
                continue;
            }

            $stepConditions[$index] = [
                'closure' => new SerializableClosure($step->condition),
                'negated' => $step->conditionNegated,
            ];
        }

        $manifest = PipelineManifest::create(
            stepClasses: $stepClasses,
            context: $resolvedContext,
            compensationMapping: $definition->compensationMapping(),
            stepConditions: $stepConditions,
            failStrategy: $definition->failStrategy,
        );

        // Story 6.1: mirror the PipelineBuilder wiring so RecordingExecutor
        // observes the same hook contract as SyncExecutor (AC #10).
        $manifest->beforeEachHooks = array_map(
            static fn (Closure $hook): SerializableClosure => new SerializableClosure($hook),
            $definition->beforeEachHooks,
        );
        $manifest->afterEachHooks = array_map(
            static fn (Closure $hook): SerializableClosure => new SerializableClosure($hook),
            $definition->afterEachHooks,
        );
        $manifest->onStepFailedHooks = array_map(
            static fn (Closure $hook): SerializableClosure => new SerializableClosure($hook),
            $definition->onStepFailedHooks,
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
        } catch (StepExecutionFailed $e) {
            $this->fake->recordPipeline(
                definition: $definition,
                recordedContext: $manifest->context,
                executedSteps: $executor->executedSteps(),
                contextSnapshots: $executor->contextSnapshots(),
                wasRecording: true,
                compensationTriggered: $executor->compensationTriggered(),
                compensationSteps: $executor->compensationSteps(),
            );

            throw $e;
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
