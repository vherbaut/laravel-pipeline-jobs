<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use LogicException;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Testing\FakePipelineBuilder;

/**
 * Auto-dispatching wrapper around PipelineBuilder mirroring Laravel's PendingDispatch idiom.
 *
 * Returned by Pipeline::dispatch([...]) / JobPipeline::dispatch([...]) and by
 * PipelineFake::dispatch([...]) under Pipeline::fake(). The wrapper exposes the
 * full PipelineBuilder fluent surface (minus the terminal verbs) as explicit
 * proxy methods. Execution is driven by __destruct(): when the wrapper instance
 * refcount drops to zero, the underlying builder's run() is invoked exactly once.
 *
 * ### Intentional exclusions
 *
 * The following terminal / definition / context-accessor methods are NOT proxied:
 * - run()          — dispatch() IS the execution verb; the destructor fires run()
 * - toListener()   — listen() is the registration verb; dispatch() is orthogonal
 * - build()        — internal definition verb not exposed at the dispatch layer
 * - return()       — dispatch() discards the builder's return value by design
 * - getContext()   — context inspection belongs on a retained builder
 *
 * Users who need any of these terminal / accessor methods must stay on
 * Pipeline::make()->run() (or Pipeline::listen() for listener registration).
 *
 * ### Destruct timing (AC #10)
 *
 * - Bare statement: the temporary wrapper's destructor fires at the end of the
 *   statement, before the next statement begins. This is the intended idiom
 *   matching Laravel's `Bus::dispatch($job);`.
 * - Variable assignment: execution is deferred until the variable goes out of
 *   scope (end of function / method / closure) or is explicitly unset(). Users
 *   who assign should prefer Pipeline::make()->run() for deterministic timing.
 *
 * ### Exception propagation (AC #11)
 *
 * Exceptions thrown from the wrapped builder's run() propagate verbatim out of
 * the destructor. PHP 7+ allows destructor exceptions to propagate during
 * normal execution; shutdown-time destruction is a caller-responsibility caveat.
 *
 * ### Exception masking
 *
 * If a proxy method call (e.g. ->send(), ->onFailure()) throws before the wrapper
 * goes out of scope, the destructor still fires $this->builder->run() on the
 * partially-configured builder. If run() then raises a second exception, PHP's
 * unwinding contract may obscure the original proxy-method exception with the
 * destructor-path one. Callers who need deterministic exception visibility should
 * prefer Pipeline::make()->run() (no destructor involved) or cancel() the wrapper
 * in a catch block before scope end.
 *
 * ### Known hazards in non-standard runtimes
 *
 * - exit() / die() on an assigned wrapper variable: PHP does not guarantee
 *   destructor invocation during process exit, so the pipeline may be silently
 *   dropped without log. Prefer bare-statement form or Pipeline::make()->run()
 *   in handlers that may exit early.
 * - pcntl_fork() (e.g. Laravel Octane, Horizon, long-running daemons): if a
 *   wrapper is alive at fork time, both parent and child run its destructor,
 *   duplicating the dispatch. Users in forking contexts should prefer
 *   Pipeline::make()->run() or cancel() the wrapper before fork.
 * - PHP shutdown with static/container-held wrappers: the destructor may fire
 *   after Laravel's container has been torn down, causing BindingResolutionException
 *   inside a destructor frame (fatal, uncatchable). Do not attach pending
 *   dispatches to static properties or request-lifetime singletons.
 */
class PendingPipelineDispatch
{
    /**
     * Idempotency guard: set to true before calling run() so a re-destruct
     * (theoretical) never double-executes the underlying pipeline.
     */
    private bool $hasRun = false;

    /**
     * Create a new pending dispatch wrapper around a concrete (or fake) builder.
     *
     * The union type accepts both the production PipelineBuilder and the
     * FakePipelineBuilder used under Pipeline::fake(); the fake duplicates the
     * fluent surface so the wrapper works identically in test contexts. The
     * property is readonly to enforce the invariant that the builder reference
     * cannot be swapped mid-chain — mutation happens THROUGH the wrapper's
     * proxy methods, which call into the builder.
     *
     * @param PipelineBuilder|FakePipelineBuilder $builder The underlying builder whose fluent surface this wrapper proxies.
     */
    public function __construct(private readonly PipelineBuilder|FakePipelineBuilder $builder) {}

    /**
     * Execute the wrapped builder's run() exactly once when the wrapper goes out of scope.
     *
     * The hasRun flag is flipped to true BEFORE the run() call so a throwing
     * run() does not cause a subsequent destruct (theoretically impossible
     * under PHP's reference-counting model) to re-execute the pipeline. The
     * return value of run() is intentionally discarded per AC #6; use
     * Pipeline::make()->run() when the final context is needed.
     *
     * Exceptions propagate verbatim (AC #11), matching Laravel's PendingDispatch.
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->hasRun) {
            return;
        }

        $this->hasRun = true;
        $this->builder->run();
    }

    /**
     * Cancel the pending dispatch so the destructor becomes a no-op.
     *
     * Opts the wrapper out of its auto-run contract: once cancel() returns, the
     * destructor short-circuits via the hasRun guard and the underlying builder
     * is never executed. Useful when a caller decides to abandon the pipeline
     * between configuration and scope end (e.g. early return, validation
     * failure) without resorting to reflection on the private hasRun flag.
     *
     * Tests that want to construct a wrapper in isolation without triggering
     * the builder's run() should call cancel() directly instead of reaching
     * into the private property via ReflectionProperty.
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->hasRun = true;
    }

    /**
     * Disable cloning: the wrapper's auto-run contract is not clone-safe.
     *
     * A cloned wrapper would inherit the same readonly builder reference but
     * reset $hasRun to false on the clone semantics, causing two destructors
     * to invoke $this->builder->run() on the same underlying builder. Rather
     * than silently double-dispatch, this hook cancels the clone so its
     * destructor becomes a no-op. The original wrapper's execution path is
     * unaffected.
     *
     * @return void
     */
    public function __clone()
    {
        $this->hasRun = true;
    }

    /**
     * Prevent serialization: the wrapper is not designed to be serialized.
     *
     * Serializing a pending dispatch would freeze the hasRun flag as false and,
     * on unserialize, trigger a second auto-run on a detached builder copy.
     * Since the builder references a live PipelineBuilder (or FakePipelineBuilder)
     * whose state cannot be meaningfully reconstructed outside the originating
     * request, the only safe behavior is to reject serialization outright.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException Always — PendingPipelineDispatch is not serializable.
     */
    public function __serialize(): array
    {
        throw new LogicException('PendingPipelineDispatch is not designed to be serialized; use Pipeline::make() to capture a serializable definition.');
    }

    /**
     * Proxies PipelineBuilder::step() and returns the wrapper for chainability.
     *
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
     * Proxies PipelineBuilder::addStep() and returns the wrapper for chainability.
     *
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
     * Proxies PipelineBuilder::addParallelGroup() and returns the wrapper for chainability.
     *
     * Append a pre-built ParallelStepGroup to the pipeline.
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
     * Proxies PipelineBuilder::parallel() and returns the wrapper for chainability.
     *
     * Append a parallel step group built from class-strings or StepDefinition instances.
     *
     * @param array<int, class-string|StepDefinition> $jobs Sub-step class-strings or pre-built StepDefinition instances (at least one).
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
     * Proxies PipelineBuilder::addNestedPipeline() and returns the wrapper for chainability.
     *
     * Append a pre-built NestedPipeline to the pipeline.
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
     * Proxies PipelineBuilder::nest() and returns the wrapper for chainability.
     *
     * Append a nested sub-pipeline built from a PipelineBuilder or PipelineDefinition.
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
     * Proxies PipelineBuilder::addConditionalBranch() and returns the wrapper for chainability.
     *
     * Append a pre-built ConditionalBranch to the pipeline.
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
     * Proxies PipelineBuilder::branch() and returns the wrapper for chainability.
     *
     * Append a conditional branch group built from a selector closure and a branches map.
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
     * Proxies PipelineBuilder::when() and returns the wrapper for chainability.
     *
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
     * Proxies PipelineBuilder::unless() and returns the wrapper for chainability.
     *
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
     * Proxies PipelineBuilder::compensateWith() and returns the wrapper for chainability.
     *
     * Assign a compensation job to the last added step for saga rollback.
     *
     * @param string $compensationClass Fully qualified class name of the compensation job.
     * @return static
     *
     * @throws InvalidPipelineDefinition When no steps have been added yet or compensation is already defined.
     */
    public function compensateWith(string $compensationClass): static
    {
        $this->builder->compensateWith($compensationClass);

        return $this;
    }

    /**
     * Proxies PipelineBuilder::onQueue() and returns the wrapper for chainability.
     *
     * Apply the given queue name to the last added step. Last-write-wins.
     *
     * @param string $queue Queue name to route the last step's wrapper dispatch to. Must be non-empty.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when the queue name is empty.
     */
    public function onQueue(string $queue): static
    {
        $this->builder->onQueue($queue);

        return $this;
    }

    /**
     * Proxies PipelineBuilder::onConnection() and returns the wrapper for chainability.
     *
     * Apply the given queue connection to the last added step. Last-write-wins.
     *
     * @param string $connection Queue connection name to route the last step's wrapper dispatch to. Must be non-empty.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when the connection name is empty.
     */
    public function onConnection(string $connection): static
    {
        $this->builder->onConnection($connection);

        return $this;
    }

    /**
     * Proxies PipelineBuilder::sync() and returns the wrapper for chainability.
     *
     * Force the last added step to run inline via dispatch_sync() when the pipeline is queued.
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
     * Proxies PipelineBuilder::retry() and returns the wrapper for chainability.
     *
     * Apply the given retry count to the last added step. Last-write-wins.
     *
     * @param int $retry Number of retry attempts after the initial attempt. Must be non-negative (0 means no retry).
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
     * Proxies PipelineBuilder::backoff() and returns the wrapper for chainability.
     *
     * Apply the given backoff delay to the last added step. Last-write-wins.
     *
     * @param int $backoff Seconds to sleep between retry attempts. Must be non-negative (0 means no sleep).
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
     * Proxies PipelineBuilder::timeout() and returns the wrapper for chainability.
     *
     * Apply the given wrapper timeout to the last added step. Last-write-wins.
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
     * Proxies PipelineBuilder::defaultQueue() and returns the wrapper for chainability.
     *
     * Declare the pipeline-level default queue for steps without explicit onQueue(). Last-write-wins.
     *
     * @param string $queue Default queue name applied to steps without an explicit onQueue() override. Must be non-empty.
     * @return static
     *
     * @throws InvalidPipelineDefinition When the queue name is empty.
     */
    public function defaultQueue(string $queue): static
    {
        $this->builder->defaultQueue($queue);

        return $this;
    }

    /**
     * Proxies PipelineBuilder::defaultConnection() and returns the wrapper for chainability.
     *
     * Declare the pipeline-level default queue connection for steps without explicit onConnection(). Last-write-wins.
     *
     * @param string $connection Default connection name applied to steps without an explicit onConnection() override. Must be non-empty.
     * @return static
     *
     * @throws InvalidPipelineDefinition When the connection name is empty.
     */
    public function defaultConnection(string $connection): static
    {
        $this->builder->defaultConnection($connection);

        return $this;
    }

    /**
     * Proxies PipelineBuilder::defaultRetry() and returns the wrapper for chainability.
     *
     * Declare the pipeline-level default retry count for steps without explicit retry(). Last-write-wins.
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
     * Proxies PipelineBuilder::defaultBackoff() and returns the wrapper for chainability.
     *
     * Declare the pipeline-level default backoff delay for steps without explicit backoff(). Last-write-wins.
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
     * Proxies PipelineBuilder::defaultTimeout() and returns the wrapper for chainability.
     *
     * Declare the pipeline-level default timeout for steps without explicit timeout(). Last-write-wins.
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
     * Proxies PipelineBuilder::send() and returns the wrapper for chainability.
     *
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
     * Proxies PipelineBuilder::shouldBeQueued() and returns the wrapper for chainability.
     *
     * Mark the pipeline as asynchronous so steps are dispatched to the queue.
     *
     * @return static
     */
    public function shouldBeQueued(): static
    {
        $this->builder->shouldBeQueued();

        return $this;
    }

    /**
     * Proxies PipelineBuilder::dispatchEvents() and returns the wrapper for chainability.
     *
     * Opt in to Laravel event dispatch for PipelineStepCompleted,
     * PipelineStepFailed, and PipelineCompleted during execution. Zero-overhead
     * when NOT called; idempotent on repeated calls. See
     * {@see PipelineBuilder::dispatchEvents()} for full semantics.
     *
     * @return static
     */
    public function dispatchEvents(): static
    {
        $this->builder->dispatchEvents();

        return $this;
    }

    /**
     * Proxies PipelineBuilder::onFailure() and returns the wrapper for chainability.
     *
     * Configure the pipeline's failure reaction (strategy enum or terminal callback).
     *
     * @param FailStrategy|(Closure(?PipelineContext, \Throwable): void) $strategyOrCallback Either the saga strategy to apply, or a callback invoked once on terminal pipeline failure.
     * @return static
     */
    public function onFailure(FailStrategy|Closure $strategyOrCallback): static
    {
        $this->builder->onFailure($strategyOrCallback);

        return $this;
    }

    /**
     * Proxies PipelineBuilder::onSuccess() and returns the wrapper for chainability.
     *
     * Register a closure invoked once when the pipeline reaches its terminal success branch.
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
     * Proxies PipelineBuilder::onComplete() and returns the wrapper for chainability.
     *
     * Register a closure invoked once when the pipeline terminates (success or failure).
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
     * Proxies PipelineBuilder::beforeEach() and returns the wrapper for chainability.
     *
     * Register a closure invoked immediately before each non-skipped step executes.
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
     * Proxies PipelineBuilder::afterEach() and returns the wrapper for chainability.
     *
     * Register a closure invoked after each step's handle() returns successfully.
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
     * Proxies PipelineBuilder::onStepFailed() and returns the wrapper for chainability.
     *
     * Register a closure invoked when a step throws (including throws from other hooks).
     *
     * @param Closure(StepDefinition, ?PipelineContext, \Throwable): void $hook Closure invoked when a step or hook throws.
     * @return static
     */
    public function onStepFailed(Closure $hook): static
    {
        $this->builder->onStepFailed($hook);

        return $this;
    }
}
