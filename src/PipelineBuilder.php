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

    /** @var string|null Pipeline-level default queue name; steps without explicit onQueue() inherit this. */
    private ?string $defaultQueue = null;

    /** @var string|null Pipeline-level default queue connection; steps without explicit onConnection() inherit this. */
    private ?string $defaultConnection = null;

    /** @var array<int, Closure> */
    private array $beforeEachHooks = [];

    /** @var array<int, Closure> */
    private array $afterEachHooks = [];

    /** @var array<int, Closure> */
    private array $onStepFailedHooks = [];

    /** @var (Closure(?PipelineContext): void)|null */
    private ?Closure $onSuccessCallback = null;

    /** @var (Closure(?PipelineContext, \Throwable): void)|null */
    private ?Closure $onFailureCallback = null;

    /** @var (Closure(?PipelineContext): void)|null */
    private ?Closure $onCompleteCallback = null;

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

        $this->steps[] = $lastStep->withCompensation($compensationClass);

        return $this;
    }

    /**
     * Apply the given queue name to the last added step.
     *
     * Replaces the last StepDefinition with a new instance carrying the
     * queue override. Last-write-wins when called twice on the same step.
     * For a pipeline-wide default, use defaultQueue() instead.
     *
     * @param string $queue Queue name to route the last step's wrapper dispatch to. Must be non-empty.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when the queue name is empty.
     */
    public function onQueue(string $queue): static
    {
        if ($queue === '') {
            throw new InvalidPipelineDefinition('Queue name passed to onQueue() cannot be empty.');
        }

        if ($this->steps === []) {
            throw new InvalidPipelineDefinition(
                'Cannot call onQueue() on PipelineBuilder before adding a step. Chain onQueue() after step(), or call defaultQueue() for a pipeline-wide default.',
            );
        }

        $last = array_pop($this->steps);
        $this->steps[] = $last->onQueue($queue);

        return $this;
    }

    /**
     * Apply the given queue connection to the last added step.
     *
     * Replaces the last StepDefinition with a new instance carrying the
     * connection override. Last-write-wins when called twice on the same
     * step. For a pipeline-wide default, use defaultConnection() instead.
     *
     * @param string $connection Queue connection name to route the last step's wrapper dispatch to. Must be non-empty.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when the connection name is empty.
     */
    public function onConnection(string $connection): static
    {
        if ($connection === '') {
            throw new InvalidPipelineDefinition('Connection name passed to onConnection() cannot be empty.');
        }

        if ($this->steps === []) {
            throw new InvalidPipelineDefinition(
                'Cannot call onConnection() on PipelineBuilder before adding a step. Chain onConnection() after step(), or call defaultConnection() for a pipeline-wide default.',
            );
        }

        $last = array_pop($this->steps);
        $this->steps[] = $last->onConnection($connection);

        return $this;
    }

    /**
     * Force the last added step to run inline in the current PHP process.
     *
     * This is NOT the Laravel "sync" queue driver. When the enclosing
     * pipeline is queued, the step is dispatched via `dispatch_sync()` so
     * it executes inline in the current worker's process before the next
     * step is scheduled. Inert in synchronous mode where every step runs
     * inline regardless of the sync flag. Replaces the last StepDefinition
     * with a new instance carrying sync=true; queue and connection overrides
     * on that step are cleared because Laravel's `dispatch_sync()` always
     * forces the connection to the literal string `'sync'` and the queue
     * value has no meaning for an inline dispatch.
     *
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added.
     */
    public function sync(): static
    {
        if ($this->steps === []) {
            throw new InvalidPipelineDefinition(
                'Cannot call sync() on PipelineBuilder before adding a step. Chain sync() after step().',
            );
        }

        $last = array_pop($this->steps);
        $this->steps[] = $last->sync();

        return $this;
    }

    /**
     * Declare the pipeline-level default queue for steps without explicit onQueue().
     *
     * Unlike onQueue(), this method is valid to call before any step has
     * been added because pipeline-level defaults are pipeline-wide. Steps
     * with explicit onQueue() override the default. Last-write-wins when
     * called multiple times. Inert when shouldBeQueued() is not set; the
     * manifest still carries the resolved values for test observability.
     *
     * @param string $queue Default queue name applied to steps without an explicit onQueue() override. Must be non-empty.
     * @return static
     *
     * @throws InvalidPipelineDefinition When the queue name is empty.
     */
    public function defaultQueue(string $queue): static
    {
        if ($queue === '') {
            throw new InvalidPipelineDefinition('Queue name passed to defaultQueue() cannot be empty.');
        }

        $this->defaultQueue = $queue;

        return $this;
    }

    /**
     * Declare the pipeline-level default queue connection for steps without explicit onConnection().
     *
     * Unlike onConnection(), this method is valid to call before any step has
     * been added because pipeline-level defaults are pipeline-wide. Steps
     * with explicit onConnection() override the default. Last-write-wins
     * when called multiple times. Inert when shouldBeQueued() is not set;
     * the manifest still carries the resolved values for test observability.
     *
     * @param string $connection Default connection name applied to steps without an explicit onConnection() override. Must be non-empty.
     * @return static
     *
     * @throws InvalidPipelineDefinition When the connection name is empty.
     */
    public function defaultConnection(string $connection): static
    {
        if ($connection === '') {
            throw new InvalidPipelineDefinition('Connection name passed to defaultConnection() cannot be empty.');
        }

        $this->defaultConnection = $connection;

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
     * Configure the pipeline's failure reaction.
     *
     * This method has two orthogonal behaviors driven by the argument type:
     *
     * Strategy branch (FailStrategy) — last-write-wins strategy setter:
     * - FailStrategy::StopAndCompensate: halts execution and runs compensation
     *   jobs in reverse order (runtime wired in Story 5.2).
     * - FailStrategy::SkipAndContinue: logs the failure, skips the failed step
     *   and continues with the next step using the last successful context
     *   (runtime wired in Story 5.3).
     * - FailStrategy::StopImmediately: halts execution without running any
     *   compensation. This is the default when onFailure() is never called
     *   (preserves Epic 1 FR28 behavior).
     *
     * Callback branch (Closure) — last-write-wins pipeline-level callback:
     * - Registers a closure invoked once on terminal pipeline failure under
     *   StopImmediately or StopAndCompensate. The callback fires AFTER per-step
     *   onStepFailed hooks (Story 6.1), AFTER compensation (sync: chain has
     *   fully run; queued: chain has been dispatched — individual jobs execute
     *   on their own workers LATER), and BEFORE the terminal rethrow. Under
     *   FailStrategy::SkipAndContinue the callback does NOT fire because
     *   there is no terminal throw.
     * - A throw from the callback closure wraps as StepExecutionFailed via
     *   StepExecutionFailed::forCallbackFailure: the callback exception is
     *   attached as $previous, and the original step exception is preserved
     *   on StepExecutionFailed::$originalStepException so observability is
     *   retained. This parity holds across sync, queued, and recording modes.
     * - Closures cross the queue boundary via SerializableClosure when the
     *   pipeline runs queued. Registrations made AFTER toListener() returns
     *   do not affect the already-built listener; the closure is captured by
     *   value at toListener() time.
     *
     * The two branches are orthogonal storage slots: calling once with a
     * FailStrategy and once with a Closure registers BOTH independently.
     * Calling twice with the same kind is last-write-wins within that kind.
     *
     * @param FailStrategy|(Closure(?PipelineContext, \Throwable): void) $strategyOrCallback Either the saga strategy to apply, or a callback invoked once on terminal pipeline failure.
     * @return static
     */
    public function onFailure(FailStrategy|Closure $strategyOrCallback): static
    {
        if ($strategyOrCallback instanceof FailStrategy) {
            $this->failStrategy = $strategyOrCallback;

            return $this;
        }

        $this->onFailureCallback = $strategyOrCallback;

        return $this;
    }

    /**
     * Register a closure invoked once when the pipeline reaches its terminal
     * success branch.
     *
     * The callback semantic is "the pipeline completed its intended flow
     * without a terminal failure" — NOT "every step succeeded". Under
     * FailStrategy::SkipAndContinue intermediate step failures are converted
     * into continuations, so the pipeline reaches the success tail and
     * onSuccess fires even when some (or all) intermediate steps were
     * skip-recovered. Users needing per-step failure observability should
     * register onStepFailed per-step hooks (Story 6.1).
     *
     * Fires on the success tail of the executor (sync mode: just before the
     * final context is returned; queued mode: on the worker that handles the
     * last step — including the terminal wrapper when the last step is
     * conditionally skipped or skip-recovered; recording mode: mirrors sync).
     *
     * Last-write-wins: calling onSuccess() multiple times silently overrides
     * the previously registered closure. When both onSuccess and onComplete
     * are registered, onSuccess fires first and onComplete second. A throw
     * from the onSuccess closure propagates unwrapped (NOT wrapped as
     * StepExecutionFailed) and aborts onComplete.
     *
     * Zero-overhead contract: when no onSuccess callback is registered, the
     * executor performs no callback check on the success tail beyond a
     * single null-guard (FR37, NFR2).
     *
     * Closures cross the queue boundary via SerializableClosure when the
     * pipeline runs queued; non-serializable closures produce the standard
     * SerializableClosure exception at dispatch time. Registrations made
     * AFTER toListener() returns do not affect the already-built listener;
     * the closure is captured by value at toListener() time.
     *
     * @param Closure(?PipelineContext): void $callback Closure invoked once on terminal pipeline success.
     * @return static
     */
    public function onSuccess(Closure $callback): static
    {
        $this->onSuccessCallback = $callback;

        return $this;
    }

    /**
     * Register a closure invoked once when the pipeline terminates (success or failure).
     *
     * Fires AFTER onSuccess on the success path and AFTER onFailure on the
     * failure path. Fires on the success tail under FailStrategy::SkipAndContinue
     * regardless of intermediate step failures (mirrors onSuccess semantic).
     *
     * Not reached when a preceding callback (onSuccess or onFailure) throws.
     *
     * Last-write-wins: calling onComplete() multiple times silently overrides
     * the previously registered closure. A throw from the onComplete closure
     * propagates as StepExecutionFailed on the failure path (wrapped via
     * StepExecutionFailed::forCallbackFailure with the original step exception
     * preserved on $originalStepException, and the callback attached as
     * $previous). On the success path an onComplete throw bubbles out
     * unwrapped because no step exception was in flight.
     *
     * Zero-overhead contract matches onSuccess: a single null-guard when the
     * callback is not registered.
     *
     * Closures cross the queue boundary via SerializableClosure when the
     * pipeline runs queued. Registrations made AFTER toListener() returns
     * do not affect the already-built listener; the closure is captured by
     * value at toListener() time.
     *
     * @param Closure(?PipelineContext): void $callback Closure invoked once on pipeline termination.
     * @return static
     */
    public function onComplete(Closure $callback): static
    {
        $this->onCompleteCallback = $callback;

        return $this;
    }

    /**
     * Register a closure invoked immediately before each non-skipped step executes.
     *
     * Fires after the step's when()/unless() condition resolves to run, after
     * container resolution, and after manifest injection; runs immediately
     * before the step's handle() method is called. Receives a minimal
     * StepDefinition snapshot (produced by StepDefinition::fromJobClass())
     * whose jobClass matches the currently executing step class, plus the
     * live PipelineContext (which may be null when no context was sent).
     *
     * Append-semantic: calling beforeEach() multiple times registers multiple
     * observers, all firing in registration order. This contrasts with
     * send(), shouldBeQueued(), return(), and onFailure() which are
     * last-write-wins.
     *
     * Zero-overhead contract: when no beforeEach hooks are registered, the
     * executor performs no hook iteration and no SerializableClosure
     * unwrap (FR37, NFR2). Hooks do NOT fire for steps skipped via
     * when()/unless() conditions.
     *
     * A throwing beforeEach is treated as a step failure: the step's
     * surrounding try/catch catches the exception, onStepFailed hooks fire
     * with the hook exception, and the FailStrategy branching then applies.
     *
     * Hook closures cross the queue boundary via SerializableClosure when
     * the pipeline runs queued; non-serializable closures produce the
     * standard SerializableClosure exception at dispatch time.
     *
     * @param Closure(StepDefinition, ?PipelineContext): void $hook Closure invoked before each non-skipped step.
     * @return static
     */
    public function beforeEach(Closure $hook): static
    {
        $this->beforeEachHooks[] = $hook;

        return $this;
    }

    /**
     * Register a closure invoked after each step's handle() returns successfully.
     *
     * Fires after the step's handle() method returns, BEFORE the manifest's
     * markStepCompleted() and advanceStep() run. Receives the same
     * StepDefinition snapshot and the live PipelineContext (with any
     * mutations performed by handle() already visible). Hooks do NOT fire
     * for steps that threw; the onStepFailed branch applies instead.
     *
     * Append-semantic like beforeEach(); multiple registrations fire in
     * order. A throwing afterEach is treated as a step failure: the step
     * is NOT marked completed, onStepFailed hooks fire with the hook
     * exception, and the FailStrategy branching then applies.
     *
     * @param Closure(StepDefinition, ?PipelineContext): void $hook Closure invoked after each successful step.
     * @return static
     */
    public function afterEach(Closure $hook): static
    {
        $this->afterEachHooks[] = $hook;

        return $this;
    }

    /**
     * Register a closure invoked when a step throws (including throws from other hooks).
     *
     * Fires inside the step's try/catch block, AFTER the manifest records
     * failureException / failedStepClass / failedStepIndex, but BEFORE the
     * FailStrategy branching (StopImmediately / StopAndCompensate /
     * SkipAndContinue all see onStepFailed fire first). Receives the
     * StepDefinition snapshot, the live PipelineContext, and the caught
     * Throwable.
     *
     * Distinct from onFailure(FailStrategy): onStepFailed() is an
     * append-semantic observability HOOK (multi-registration, observes
     * failures); onFailure(FailStrategy) is a last-write-wins STRATEGY
     * setter (chooses StopImmediately / StopAndCompensate / SkipAndContinue).
     * The two are orthogonal and may be used together.
     *
     * A throwing onStepFailed hook propagates and bypasses the
     * FailStrategy branching for the CURRENT failure: no compensation
     * chain dispatch, no SkipAndContinue advance. The hook exception
     * replaces the original step exception as the bubbling fault.
     * Subsequent onStepFailed hooks in the array do NOT fire (the loop
     * aborts on first throw).
     *
     * @param Closure(StepDefinition, ?PipelineContext, \Throwable): void $hook Closure invoked when a step or hook throws.
     * @return static
     */
    public function onStepFailed(Closure $hook): static
    {
        $this->onStepFailedHooks[] = $hook;

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
            beforeEachHooks: $this->beforeEachHooks,
            afterEachHooks: $this->afterEachHooks,
            onStepFailedHooks: $this->onStepFailedHooks,
            onComplete: $this->onCompleteCallback,
            onSuccess: $this->onSuccessCallback,
            onFailure: $this->onFailureCallback,
            failStrategy: $this->failStrategy,
            defaultQueue: $this->defaultQueue,
            defaultConnection: $this->defaultConnection,
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

        $stepConfigs = self::resolveStepConfigs($definition);

        $manifest = PipelineManifest::create(
            stepClasses: $stepClasses,
            context: $resolvedContext,
            compensationMapping: $definition->compensationMapping(),
            stepConditions: $this->buildStepConditions($definition),
            failStrategy: $definition->failStrategy,
            stepConfigs: $stepConfigs,
        );

        $hookClosures = $this->buildHookSerializableClosures($definition);
        $manifest->beforeEachHooks = $hookClosures['beforeEach'];
        $manifest->afterEachHooks = $hookClosures['afterEach'];
        $manifest->onStepFailedHooks = $hookClosures['onStepFailed'];

        $callbackClosures = $this->buildCallbackSerializableClosures($definition);
        $manifest->onCompleteCallback = $callbackClosures['onComplete'];
        $manifest->onSuccessCallback = $callbackClosures['onSuccess'];
        $manifest->onFailureCallback = $callbackClosures['onFailure'];

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
        $stepConfigs = self::resolveStepConfigs($definition);
        $hookClosures = $this->buildHookSerializableClosures($definition);
        $callbackClosures = $this->buildCallbackSerializableClosures($definition);

        return function (object $event) use ($definition, $contextSource, $shouldBeQueued, $stepConditions, $stepConfigs, $hookClosures, $callbackClosures): void {
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
                failStrategy: $definition->failStrategy,
                stepConfigs: $stepConfigs,
            );

            $manifest->beforeEachHooks = $hookClosures['beforeEach'];
            $manifest->afterEachHooks = $hookClosures['afterEach'];
            $manifest->onStepFailedHooks = $hookClosures['onStepFailed'];

            $manifest->onCompleteCallback = $callbackClosures['onComplete'];
            $manifest->onSuccessCallback = $callbackClosures['onSuccess'];
            $manifest->onFailureCallback = $callbackClosures['onFailure'];

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
     * serialization boundary. Mirrors the hook-wrapping helper
     * buildHookSerializableClosures() which applies the same pattern to
     * the three pipeline-level hook arrays.
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

    /**
     * Wrap the pipeline's hook closures in SerializableClosure for queue transport.
     *
     * Iterates the three hook arrays carried on the PipelineDefinition and
     * produces a SerializableClosure for each registered Closure so the
     * resulting manifest can be serialized onto a queue payload. Mirrors
     * the buildStepConditions() pattern. The returned shape is assigned
     * directly onto the manifest's three public mutable hook fields.
     *
     * @param PipelineDefinition $definition The built pipeline definition carrying the raw Closure arrays.
     *
     * @return array{beforeEach: array<int, SerializableClosure>, afterEach: array<int, SerializableClosure>, onStepFailed: array<int, SerializableClosure>} Wrapped hook closures keyed by hook kind.
     */
    private function buildHookSerializableClosures(PipelineDefinition $definition): array
    {
        return [
            'beforeEach' => array_map(
                static fn (Closure $hook): SerializableClosure => new SerializableClosure($hook),
                $definition->beforeEachHooks,
            ),
            'afterEach' => array_map(
                static fn (Closure $hook): SerializableClosure => new SerializableClosure($hook),
                $definition->afterEachHooks,
            ),
            'onStepFailed' => array_map(
                static fn (Closure $hook): SerializableClosure => new SerializableClosure($hook),
                $definition->onStepFailedHooks,
            ),
        ];
    }

    /**
     * Wrap the pipeline-level callback closures in SerializableClosure for queue transport.
     *
     * Mirrors buildHookSerializableClosures() but for the three nullable
     * singular callback slots (onComplete, onSuccess, onFailure) rather
     * than the append-semantic hook arrays. Null callback slots on the
     * definition produce null SerializableClosure slots on the returned
     * array so the manifest's null-guard fast path remains intact.
     *
     * @param PipelineDefinition $definition The built pipeline definition carrying the three nullable Closure slots.
     *
     * @return array{onComplete: SerializableClosure|null, onSuccess: SerializableClosure|null, onFailure: SerializableClosure|null} Wrapped callback closures keyed by callback kind.
     */
    private function buildCallbackSerializableClosures(PipelineDefinition $definition): array
    {
        return [
            'onComplete' => $definition->onComplete === null
                ? null
                : new SerializableClosure($definition->onComplete),
            'onSuccess' => $definition->onSuccess === null
                ? null
                : new SerializableClosure($definition->onSuccess),
            'onFailure' => $definition->onFailure === null
                ? null
                : new SerializableClosure($definition->onFailure),
        ];
    }

    /**
     * Build the per-step execution configuration for the manifest.
     *
     * Iterates the definition's steps and resolves queue / connection / sync
     * values following the precedence rule `step override > pipeline default
     * > null`. Sync has no pipeline-level default; the step's own sync flag
     * is carried verbatim.
     *
     * Called once at manifest creation time in run() and toListener() so
     * executors consume fully-resolved values without re-running precedence
     * logic at every dispatch point. Reads the pipeline-level defaults from
     * the definition so external consumers of the definition can reproduce
     * the same resolution without access to the builder.
     *
     * @param PipelineDefinition $definition The built pipeline definition carrying steps and pipeline-level defaults.
     *
     * @return array<int, array{queue: ?string, connection: ?string, sync: bool}> Resolved per-step config indexed by step position.
     */
    public static function resolveStepConfigs(PipelineDefinition $definition): array
    {
        $configs = [];

        foreach ($definition->steps as $index => $step) {
            $configs[$index] = [
                'queue' => $step->queue ?? $definition->defaultQueue,
                'connection' => $step->connection ?? $definition->defaultConnection,
                'sync' => $step->sync,
            ];
        }

        return $configs;
    }
}
