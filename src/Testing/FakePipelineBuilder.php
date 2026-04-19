<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use Vherbaut\LaravelPipelineJobs\ConditionalBranch;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\NestedPipeline;
use Vherbaut\LaravelPipelineJobs\ParallelStepGroup;
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
     * Append a pre-built ParallelStepGroup to the pipeline.
     *
     * Delegates to the underlying PipelineBuilder so the recorded
     * PipelineDefinition carries the parallel group at its outer position
     * for assertion purposes. In Pipeline::fake() default mode the pipeline
     * does not execute; in recording mode the RecordingExecutor replays
     * sub-steps sequentially identically to SyncExecutor.
     *
     * @param ParallelStepGroup $group Pre-built parallel group containing at least one sub-step.
     * @return static
     */
    public function addParallelGroup(ParallelStepGroup $group): static
    {
        $this->builder->addParallelGroup($group);

        return $this;
    }

    /**
     * Append a parallel step group built from class-strings or StepDefinition instances.
     *
     * Fluent shorthand for addParallelGroup(ParallelStepGroup::fromArray($jobs)).
     *
     * @param array<int, class-string|StepDefinition> $jobs Sub-step class-strings or pre-built StepDefinition instances.
     * @return static
     *
     * @throws InvalidPipelineDefinition When $jobs is empty or contains an unsupported item type.
     */
    public function parallel(array $jobs): static
    {
        $this->builder->parallel($jobs);

        return $this;
    }

    /**
     * Append a pre-built NestedPipeline to the fake pipeline.
     *
     * Delegates to the underlying PipelineBuilder so the recorded
     * PipelineDefinition carries the nested wrapper at its outer position
     * for assertion purposes. In Pipeline::fake() default mode the pipeline
     * does not execute; in recording mode the RecordingExecutor replays
     * inner steps sequentially identically to SyncExecutor.
     *
     * @param NestedPipeline $nested Pre-built nested pipeline wrapping an inner PipelineDefinition.
     * @return static
     */
    public function addNestedPipeline(NestedPipeline $nested): static
    {
        $this->builder->addNestedPipeline($nested);

        return $this;
    }

    /**
     * Append a nested sub-pipeline built from a PipelineBuilder or PipelineDefinition.
     *
     * Fluent shorthand for addNestedPipeline(NestedPipeline::from...($pipeline, $name)).
     *
     * @param PipelineBuilder|PipelineDefinition $pipeline Inner pipeline to wrap; builder form is built eagerly at wrap time.
     * @param string|null $name Optional user-visible sub-pipeline name for observability; defaults to null.
     * @return static
     *
     * @throws InvalidPipelineDefinition Propagated from PipelineBuilder::build() when called with a builder that has no steps.
     */
    public function nest(PipelineBuilder|PipelineDefinition $pipeline, ?string $name = null): static
    {
        $this->builder->nest($pipeline, $name);

        return $this;
    }

    /**
     * Append a pre-built ConditionalBranch to the fake pipeline.
     *
     * Delegates to the underlying PipelineBuilder so the recorded
     * PipelineDefinition carries the branch wrapper at its outer position
     * for assertion purposes. In Pipeline::fake() default mode the pipeline
     * does not execute; in recording mode the RecordingExecutor replays
     * the selected branch's inner step(s) identically to SyncExecutor.
     *
     * @param ConditionalBranch $branch Pre-built conditional branch wrapping a selector and branch values.
     * @return static
     */
    public function addConditionalBranch(ConditionalBranch $branch): static
    {
        $this->builder->addConditionalBranch($branch);

        return $this;
    }

    /**
     * Append a conditional branch group built from a selector closure and a branches map.
     *
     * Fluent shorthand for addConditionalBranch(ConditionalBranch::fromArray($selector, $branches, $name)).
     *
     * @param Closure $selector Selector closure typed Closure(PipelineContext): string.
     * @param array<array-key, mixed> $branches Map of branch keys to step values (class-string, StepDefinition, NestedPipeline, PipelineBuilder, or PipelineDefinition).
     * @param string|null $name Optional user-visible branch name for observability; defaults to null.
     * @return static
     *
     * @throws InvalidPipelineDefinition When $branches is empty, carries a blank key, contains a ParallelStepGroup, or contains an unsupported value type.
     */
    public function branch(Closure $selector, array $branches, ?string $name = null): static
    {
        $this->builder->branch($selector, $branches, $name);

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
     * Apply the given queue name to the last added step.
     *
     * Delegates to PipelineBuilder::onQueue() so the resolved configuration
     * is captured on the recorded PipelineDefinition for assertion purposes.
     * In Pipeline::fake() default mode the pipeline does not execute, so
     * queue routing has no runtime effect. In Pipeline::fake()->recording()
     * mode, queue routing is inert because RecordingExecutor runs everything
     * in-process (same inert semantics as SyncExecutor).
     *
     * @param string $queue Queue name to route the last step's wrapper dispatch to.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added.
     */
    public function onQueue(string $queue): static
    {
        $this->builder->onQueue($queue);

        return $this;
    }

    /**
     * Apply the given queue connection to the last added step.
     *
     * Delegates to PipelineBuilder::onConnection() with the same
     * fake-mode-inert semantics as onQueue().
     *
     * @param string $connection Queue connection name for the last step's wrapper dispatch.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added.
     */
    public function onConnection(string $connection): static
    {
        $this->builder->onConnection($connection);

        return $this;
    }

    /**
     * Force the last added step to run inline in the current PHP process.
     *
     * Delegates to PipelineBuilder::sync(). This is NOT the Laravel "sync"
     * queue driver; it forces inline execution via `dispatch_sync()` when
     * the pipeline runs queued. Inert in Pipeline::fake() default and
     * Pipeline::fake()->recording() modes (no actual queue dispatch occurs);
     * the configuration is captured on the recorded definition for
     * observability.
     *
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added.
     */
    public function sync(): static
    {
        $this->builder->sync();

        return $this;
    }

    /**
     * Declare the pipeline-level default queue for steps without explicit onQueue().
     *
     * Delegates to PipelineBuilder::defaultQueue(). Inert in Pipeline::fake()
     * default and Pipeline::fake()->recording() modes; the value is carried
     * on the underlying builder for test observability.
     *
     * @param string $queue Default queue name applied to steps without an explicit onQueue() override.
     * @return static
     */
    public function defaultQueue(string $queue): static
    {
        $this->builder->defaultQueue($queue);

        return $this;
    }

    /**
     * Declare the pipeline-level default queue connection for steps without explicit onConnection().
     *
     * Delegates to PipelineBuilder::defaultConnection(). Inert in
     * Pipeline::fake() default and Pipeline::fake()->recording() modes.
     *
     * @param string $connection Default connection name applied to steps without an explicit onConnection() override.
     * @return static
     */
    public function defaultConnection(string $connection): static
    {
        $this->builder->defaultConnection($connection);

        return $this;
    }

    /**
     * Apply the given retry count to the last added step.
     *
     * Delegates to PipelineBuilder::retry(). In Pipeline::fake() default mode
     * the pipeline does not execute, so retry has no runtime effect; the
     * resolved `stepConfigs` is captured on the recorded definition for
     * assertion purposes. In Pipeline::fake()->recording() mode, retry is
     * inert because RecordingExecutor runs each step exactly once. The
     * underlying PipelineBuilder validation guards (no-prior-step + negative
     * value rejection) remain ACTIVE in fake and recording modes — only the
     * runtime retry-loop side effect is inert.
     *
     * @param int $retry Number of retry attempts after the initial attempt. Must be non-negative.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when $retry is negative.
     */
    public function retry(int $retry): static
    {
        $this->builder->retry($retry);

        return $this;
    }

    /**
     * Apply the given backoff delay to the last added step.
     *
     * Delegates to PipelineBuilder::backoff() with the same fake-mode-inert
     * semantics as retry(). Validation guards (no-prior-step + negative
     * value rejection) remain ACTIVE in fake and recording modes.
     *
     * @param int $backoff Seconds to sleep between retry attempts. Must be non-negative.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when $backoff is negative.
     */
    public function backoff(int $backoff): static
    {
        $this->builder->backoff($backoff);

        return $this;
    }

    /**
     * Apply the given wrapper timeout to the last added step.
     *
     * Delegates to PipelineBuilder::timeout(). Inert in Pipeline::fake()
     * default and Pipeline::fake()->recording() modes (no actual queue
     * dispatch occurs); the configuration is captured on the recorded
     * definition for observability. Validation guards (no-prior-step +
     * `>= 1` enforcement) remain ACTIVE in fake and recording modes.
     *
     * @param int $timeout Maximum execution time in seconds for the queued wrapper. Must be greater than or equal to 1.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when $timeout is less than 1.
     */
    public function timeout(int $timeout): static
    {
        $this->builder->timeout($timeout);

        return $this;
    }

    /**
     * Declare the pipeline-level default retry count for steps without explicit retry().
     *
     * Delegates to PipelineBuilder::defaultRetry(). Inert in Pipeline::fake()
     * default and Pipeline::fake()->recording() modes; the value is carried
     * on the underlying builder for test observability. The negative-value
     * validation guard remains ACTIVE in fake and recording modes.
     *
     * @param int $retry Default retry attempts applied to steps without an explicit retry() override. Must be non-negative.
     * @return static
     *
     * @throws InvalidPipelineDefinition When $retry is negative.
     */
    public function defaultRetry(int $retry): static
    {
        $this->builder->defaultRetry($retry);

        return $this;
    }

    /**
     * Declare the pipeline-level default backoff delay for steps without explicit backoff().
     *
     * Delegates to PipelineBuilder::defaultBackoff(). Inert in Pipeline::fake()
     * default and Pipeline::fake()->recording() modes. The negative-value
     * validation guard remains ACTIVE in fake and recording modes.
     *
     * @param int $backoff Default backoff (seconds) applied to steps without an explicit backoff() override. Must be non-negative.
     * @return static
     *
     * @throws InvalidPipelineDefinition When $backoff is negative.
     */
    public function defaultBackoff(int $backoff): static
    {
        $this->builder->defaultBackoff($backoff);

        return $this;
    }

    /**
     * Declare the pipeline-level default timeout for steps without explicit timeout().
     *
     * Delegates to PipelineBuilder::defaultTimeout(). Inert in Pipeline::fake()
     * default and Pipeline::fake()->recording() modes. The `>= 1` validation
     * guard remains ACTIVE in fake and recording modes.
     *
     * @param int $timeout Default timeout (seconds) applied to steps without an explicit timeout() override. Must be greater than or equal to 1.
     * @return static
     *
     * @throws InvalidPipelineDefinition When $timeout is less than 1.
     */
    public function defaultTimeout(int $timeout): static
    {
        $this->builder->defaultTimeout($timeout);

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
     * Opt in to Laravel event dispatch for pipeline lifecycle events.
     *
     * Delegates to {@see PipelineBuilder::dispatchEvents()} with identical
     * semantics: zero-overhead when NOT called, idempotent on repeated calls.
     * The flag reaches the manifest through executeWithRecording() so
     * RecordingExecutor honors the opt-in and fires PipelineStepCompleted,
     * PipelineStepFailed, and PipelineCompleted under
     * Pipeline::fake()->recording().
     *
     * @return static
     */
    public function dispatchEvents(): static
    {
        $this->builder->dispatchEvents();

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
     * Configure the pipeline's failure reaction (strategy or callback).
     *
     * Delegates to PipelineBuilder::onFailure() with identical semantics.
     *
     * Strategy branch (FailStrategy) — last-write-wins saga strategy:
     * - FailStrategy::StopAndCompensate: halts execution and runs compensation
     *   jobs in reverse order (runtime wired in Story 5.2).
     * - FailStrategy::SkipAndContinue: logs the failure, skips the failed step
     *   and continues with the next step using the last successful context
     *   (runtime wired in Story 5.3).
     * - FailStrategy::StopImmediately: halts execution without running any
     *   compensation. This is the default when onFailure() is never called.
     *
     * Callback branch (Closure) — last-write-wins pipeline-level failure
     * callback invoked once on terminal failure under StopImmediately /
     * StopAndCompensate. In Pipeline::fake() default mode the callback does
     * NOT fire (no execution); in Pipeline::fake()->recording() it fires via
     * RecordingExecutor.
     *
     * The two branches are orthogonal storage slots: calling once with a
     * FailStrategy and once with a Closure registers BOTH independently.
     *
     * @param FailStrategy|Closure(?PipelineContext, \Throwable): void $strategyOrCallback Either the saga strategy or a callback invoked once on terminal pipeline failure.
     * @return static
     */
    public function onFailure(FailStrategy|Closure $strategyOrCallback): static
    {
        $this->builder->onFailure($strategyOrCallback);

        return $this;
    }

    /**
     * Register a closure invoked once when the pipeline terminates successfully.
     *
     * Delegates to PipelineBuilder::onSuccess(). In Pipeline::fake() default
     * mode the callback does NOT fire (no execution); in
     * Pipeline::fake()->recording() it fires via RecordingExecutor with the
     * same contract as SyncExecutor.
     *
     * Last-write-wins: calling onSuccess() multiple times silently overrides
     * the previously registered closure.
     *
     * @param Closure(?PipelineContext): void $callback Closure invoked once on terminal pipeline success.
     * @return static
     */
    public function onSuccess(Closure $callback): static
    {
        $this->builder->onSuccess($callback);

        return $this;
    }

    /**
     * Register a closure invoked once when the pipeline terminates (success or failure).
     *
     * Delegates to PipelineBuilder::onComplete(). In Pipeline::fake() default
     * mode the callback does NOT fire (no execution); in
     * Pipeline::fake()->recording() it fires via RecordingExecutor with the
     * same contract as SyncExecutor.
     *
     * Last-write-wins: calling onComplete() multiple times silently overrides
     * the previously registered closure.
     *
     * @param Closure(?PipelineContext): void $callback Closure invoked once on pipeline termination.
     * @return static
     */
    public function onComplete(Closure $callback): static
    {
        $this->builder->onComplete($callback);

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
     * Return a new FakePipelineBuilder wrapping a reversed inner PipelineBuilder.
     *
     * Delegates to {@see PipelineBuilder::reverse()} with identical semantics:
     * the returned fake builder wraps a NEW underlying PipelineBuilder whose
     * outer-position steps are reversed, and carries the same PipelineFake
     * reference plus the currently registered return callback slot. The
     * original fake remains untouched and available for further configuration
     * and assertion against subsequent runs.
     *
     * Recording-mode parity: when the enclosing fake was switched into
     * recording mode via {@see PipelineFake::recording()},
     * {@see self::executeWithRecording()} consumes the reversed definition
     * exactly as any non-reversed definition, so recorded step order and any
     * opt-in PipelineStepCompleted / PipelineCompleted events fire in the
     * reversed order.
     *
     * @return static A NEW FakePipelineBuilder wrapping a reversed inner builder.
     */
    public function reverse(): static
    {
        $clone = new self($this->fake);
        $clone->builder = $this->builder->reverse();
        $clone->returnCallback = $this->returnCallback;

        return $clone;
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
        $stepClasses = [];

        foreach ($definition->steps as $index => $step) {
            if ($step instanceof ParallelStepGroup) {
                $stepClasses[$index] = [
                    'type' => 'parallel',
                    'classes' => array_map(
                        static fn (StepDefinition $subStep): string => $subStep->jobClass,
                        $step->steps,
                    ),
                ];

                continue;
            }

            if ($step instanceof NestedPipeline) {
                $stepClasses[$index] = self::buildNestedStepClassesPayload($step);

                continue;
            }

            if ($step instanceof ConditionalBranch) {
                $stepClasses[$index] = self::buildConditionalBranchStepClassesPayload($step);

                continue;
            }

            $stepClasses[$index] = $step->jobClass;
        }

        $stepConditions = [];

        foreach ($definition->steps as $index => $step) {
            if ($step instanceof ParallelStepGroup) {
                $entries = [];
                $hasAny = false;

                foreach ($step->steps as $subIndex => $subStep) {
                    if ($subStep->condition === null) {
                        $entries[$subIndex] = null;

                        continue;
                    }

                    $entries[$subIndex] = [
                        'closure' => new SerializableClosure($subStep->condition),
                        'negated' => $subStep->conditionNegated,
                    ];
                    $hasAny = true;
                }

                if ($hasAny) {
                    $stepConditions[$index] = [
                        'type' => 'parallel',
                        'entries' => $entries,
                    ];
                }

                continue;
            }

            if ($step instanceof NestedPipeline) {
                $nestedConditions = self::buildNestedStepConditionsPayload($step);

                if ($nestedConditions !== null) {
                    $stepConditions[$index] = $nestedConditions;
                }

                continue;
            }

            if ($step instanceof ConditionalBranch) {
                $branchConditions = self::buildConditionalBranchStepConditionsPayload($step);

                if ($branchConditions !== null) {
                    $stepConditions[$index] = $branchConditions;
                }

                continue;
            }

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
            stepConfigs: PipelineBuilder::resolveStepConfigs($definition),
            dispatchEvents: $definition->dispatchEvents,
        );

        // Mirror the PipelineBuilder wiring so RecordingExecutor observes
        // the same hook and callback contract as SyncExecutor.
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

        $manifest->onSuccessCallback = $definition->onSuccess === null
            ? null
            : new SerializableClosure($definition->onSuccess);
        $manifest->onFailureCallback = $definition->onFailure === null
            ? null
            : new SerializableClosure($definition->onFailure);
        $manifest->onCompleteCallback = $definition->onComplete === null
            ? null
            : new SerializableClosure($definition->onComplete);

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

    /**
     * Recursively build the nested-shape step-classes payload for a NestedPipeline entry.
     *
     * Mirrors PipelineBuilder::buildNestedStepClassesPayload() so the
     * RecordingExecutor sees the same discriminator-tagged recursive shape
     * as SyncExecutor under Pipeline::fake()->recording() (AC #14).
     *
     * @param NestedPipeline $nested The nested wrapper whose inner definition is walked.
     *
     * @return array{type: string, name: ?string, steps: array<int, string|array<string, mixed>>} The nested discriminator-tagged payload.
     */
    private static function buildNestedStepClassesPayload(NestedPipeline $nested): array
    {
        $innerSteps = [];

        foreach ($nested->definition->steps as $subIndex => $subStep) {
            if ($subStep instanceof ParallelStepGroup) {
                $innerSteps[$subIndex] = [
                    'type' => 'parallel',
                    'classes' => array_map(
                        static fn (StepDefinition $grandSubStep): string => $grandSubStep->jobClass,
                        $subStep->steps,
                    ),
                ];

                continue;
            }

            if ($subStep instanceof NestedPipeline) {
                $innerSteps[$subIndex] = self::buildNestedStepClassesPayload($subStep);

                continue;
            }

            if ($subStep instanceof ConditionalBranch) {
                $innerSteps[$subIndex] = self::buildConditionalBranchStepClassesPayload($subStep);

                continue;
            }

            $innerSteps[$subIndex] = $subStep->jobClass;
        }

        return [
            'type' => 'nested',
            'name' => $nested->name,
            'steps' => $innerSteps,
        ];
    }

    /**
     * Recursively build the branch-shape step-classes payload for a ConditionalBranch entry.
     *
     * Mirrors PipelineBuilder::buildConditionalBranchStepClassesPayload() so
     * the RecordingExecutor sees the same branch discriminator-tagged shape
     * as SyncExecutor under Pipeline::fake()->recording().
     *
     * @param ConditionalBranch $branch The branch wrapper whose branches map is serialized.
     *
     * @return array{type: string, name: ?string, selector: SerializableClosure, branches: array<string, string|array<string, mixed>>} The branch discriminator-tagged shape.
     */
    private static function buildConditionalBranchStepClassesPayload(ConditionalBranch $branch): array
    {
        $branches = [];

        foreach ($branch->branches as $key => $value) {
            if ($value instanceof NestedPipeline) {
                $branches[$key] = self::buildNestedStepClassesPayload($value);

                continue;
            }

            $branches[$key] = $value->jobClass;
        }

        return [
            'type' => 'branch',
            'name' => $branch->name,
            'selector' => new SerializableClosure($branch->selector),
            'branches' => $branches,
        ];
    }

    /**
     * Recursively build the branch-shape condition payload for a ConditionalBranch entry.
     *
     * Mirrors PipelineBuilder::buildConditionalBranchStepConditionsPayload()
     * for recording-mode parity.
     *
     * @param ConditionalBranch $branch The branch wrapper whose branch conditions are walked.
     *
     * @return array{type: string, entries: array<string, array<string, mixed>|null>}|null The branch discriminator-tagged payload, or null when no branch entry carries any condition.
     */
    private static function buildConditionalBranchStepConditionsPayload(ConditionalBranch $branch): ?array
    {
        $entries = [];
        $hasAny = false;

        foreach ($branch->branches as $key => $value) {
            if ($value instanceof NestedPipeline) {
                $nested = self::buildNestedStepConditionsPayload($value);

                if ($nested !== null) {
                    $entries[$key] = $nested;
                    $hasAny = true;
                } else {
                    $entries[$key] = null;
                }

                continue;
            }

            if ($value->condition === null) {
                $entries[$key] = null;

                continue;
            }

            $entries[$key] = [
                'closure' => new SerializableClosure($value->condition),
                'negated' => $value->conditionNegated,
            ];
            $hasAny = true;
        }

        if (! $hasAny) {
            return null;
        }

        return [
            'type' => 'branch',
            'entries' => $entries,
        ];
    }

    /**
     * Recursively build the nested-shape condition payload for a NestedPipeline entry.
     *
     * Mirrors PipelineBuilder::buildNestedStepConditionsPayload() so
     * RecordingExecutor evaluates inner conditions correctly in recording
     * mode. Returns null when the nested group carries no conditions at any
     * level so the outer code can omit the entry from the manifest for a
     * leaner payload (matches the parallel-group lean-payload precedent).
     *
     * @param NestedPipeline $nested The nested wrapper whose inner definition's conditions are walked.
     *
     * @return array{type: string, entries: array<int, array<string, mixed>|null>}|null The nested discriminator-tagged payload, or null when none of the inner entries carry a condition.
     */
    private static function buildNestedStepConditionsPayload(NestedPipeline $nested): ?array
    {
        $entries = [];
        $hasAny = false;

        foreach ($nested->definition->steps as $subIndex => $subStep) {
            if ($subStep instanceof ParallelStepGroup) {
                $parallelEntries = [];
                $parallelHasAny = false;

                foreach ($subStep->steps as $grandSubIndex => $grandSubStep) {
                    if ($grandSubStep->condition === null) {
                        $parallelEntries[$grandSubIndex] = null;

                        continue;
                    }

                    $parallelEntries[$grandSubIndex] = [
                        'closure' => new SerializableClosure($grandSubStep->condition),
                        'negated' => $grandSubStep->conditionNegated,
                    ];
                    $parallelHasAny = true;
                }

                if ($parallelHasAny) {
                    $entries[$subIndex] = [
                        'type' => 'parallel',
                        'entries' => $parallelEntries,
                    ];
                    $hasAny = true;
                } else {
                    $entries[$subIndex] = null;
                }

                continue;
            }

            if ($subStep instanceof NestedPipeline) {
                $innerNested = self::buildNestedStepConditionsPayload($subStep);

                if ($innerNested !== null) {
                    $entries[$subIndex] = $innerNested;
                    $hasAny = true;
                } else {
                    $entries[$subIndex] = null;
                }

                continue;
            }

            if ($subStep instanceof ConditionalBranch) {
                $innerBranch = self::buildConditionalBranchStepConditionsPayload($subStep);

                if ($innerBranch !== null) {
                    $entries[$subIndex] = $innerBranch;
                    $hasAny = true;
                } else {
                    $entries[$subIndex] = null;
                }

                continue;
            }

            if ($subStep->condition === null) {
                $entries[$subIndex] = null;

                continue;
            }

            $entries[$subIndex] = [
                'closure' => new SerializableClosure($subStep->condition),
                'negated' => $subStep->conditionNegated,
            ];
            $hasAny = true;
        }

        if (! $hasAny) {
            return null;
        }

        return [
            'type' => 'nested',
            'entries' => $entries,
        ];
    }
}
