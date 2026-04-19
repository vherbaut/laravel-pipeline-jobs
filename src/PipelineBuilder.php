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
    /** @var array<int, StepDefinition|ParallelStepGroup|NestedPipeline|ConditionalBranch> */
    private array $steps = [];

    private PipelineContext|Closure|null $context = null;

    private bool $shouldBeQueued = false;

    private bool $dispatchEvents = false;

    private ?Closure $returnCallback = null;

    private FailStrategy $failStrategy = FailStrategy::StopImmediately;

    /** @var string|null Pipeline-level default queue name; steps without explicit onQueue() inherit this. */
    private ?string $defaultQueue = null;

    /** @var string|null Pipeline-level default queue connection; steps without explicit onConnection() inherit this. */
    private ?string $defaultConnection = null;

    /** @var int|null Pipeline-level default retry count; steps without explicit retry() inherit this. */
    private ?int $defaultRetry = null;

    /** @var int|null Pipeline-level default backoff delay in seconds; steps without explicit backoff() inherit this. */
    private ?int $defaultBackoff = null;

    /** @var int|null Pipeline-level default timeout in seconds; steps without explicit timeout() inherit this. */
    private ?int $defaultTimeout = null;

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
     * Accepts a mixed list of job class names, pre-built StepDefinition
     * instances, ParallelStepGroup instances, NestedPipeline instances, and
     * ConditionalBranch instances. Strings are converted via
     * StepDefinition::fromJobClass(); StepDefinition instances (produced by
     * Step::when(), Step::unless(), or Step::make()) are appended as-is;
     * ParallelStepGroup instances are appended as a single outer position
     * whose sub-steps fan out at execution time; NestedPipeline instances
     * are appended as a single outer position whose inner pipeline runs
     * sequentially with the shared PipelineContext; ConditionalBranch
     * instances are appended as a single outer position whose selector
     * closure resolves ONE branch path at runtime. For ergonomic authoring,
     * bare PipelineBuilder and PipelineDefinition entries are auto-wrapped
     * into NestedPipeline (with a null name) so callers can inline a
     * sub-pipeline directly in the array form. Any other type triggers
     * InvalidPipelineDefinition at construction time so user code does not
     * silently build an invalid pipeline at runtime.
     *
     * The declared item type intentionally widens to mixed because callers
     * may pass untrusted data (e.g., from configuration) and the runtime
     * check exists precisely to catch that case.
     *
     * @param array<int, mixed> $jobs Job class names, pre-built StepDefinition instances, ParallelStepGroup instances, NestedPipeline instances, ConditionalBranch instances, or bare PipelineBuilder / PipelineDefinition instances auto-wrapped into NestedPipeline.
     * @return void
     *
     * @throws InvalidPipelineDefinition When an array item is none of: class-string, StepDefinition, ParallelStepGroup, NestedPipeline, ConditionalBranch, PipelineBuilder, or PipelineDefinition; also propagated from PipelineBuilder::build() when auto-wrapping an empty builder.
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

            if ($job instanceof ParallelStepGroup) {
                $this->steps[] = $job;

                continue;
            }

            if ($job instanceof NestedPipeline) {
                $this->steps[] = $job;

                continue;
            }

            if ($job instanceof ConditionalBranch) {
                $this->steps[] = $job;

                continue;
            }

            if ($job instanceof self) {
                $this->steps[] = NestedPipeline::fromBuilder($job);

                continue;
            }

            if ($job instanceof PipelineDefinition) {
                $this->steps[] = NestedPipeline::fromDefinition($job);

                continue;
            }

            throw new InvalidPipelineDefinition(
                'Pipeline definition items must be class-string, StepDefinition, ParallelStepGroup, NestedPipeline, or ConditionalBranch instances '
                .'(PipelineBuilder and PipelineDefinition are auto-wrapped into NestedPipeline), got '.get_debug_type($job).'.',
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
     * Append a pre-built ParallelStepGroup to the pipeline.
     *
     * Symmetric with addStep(): the group occupies one outer position in the
     * pipeline's internal steps array. Sub-steps are NOT flattened; their
     * fan-out happens at execution time (Bus::batch() when queued,
     * sequential sync in SyncExecutor). Exposed publicly so advanced callers
     * can hand-craft a group via ParallelStepGroup::fromArray() and pass it
     * through addParallelGroup() without going through the fluent parallel()
     * shortcut.
     *
     * @param ParallelStepGroup $group Pre-built parallel group containing at least one sub-step.
     *
     * @return static
     */
    public function addParallelGroup(ParallelStepGroup $group): static
    {
        $this->steps[] = $group;

        return $this;
    }

    /**
     * Append a parallel step group built from an array of class-strings or StepDefinition instances.
     *
     * Fluent shorthand for addParallelGroup(ParallelStepGroup::fromArray($jobs)).
     *
     * Conditions on parallel groups are not supported in Epic 8 Story 8.1.
     * Apply Step::when() / Step::unless() to individual sub-steps before
     * wrapping them into the group. Likewise, per-step mutators
     * (compensateWith, onQueue, onConnection, sync, retry, backoff, timeout)
     * chained immediately after parallel() throw InvalidPipelineDefinition;
     * apply those mutators to individual sub-steps beforehand.
     *
     * @param array<int, class-string|StepDefinition> $jobs Sub-step class-strings or pre-built StepDefinition instances (at least one).
     *
     * @return static
     *
     * @throws InvalidPipelineDefinition When $jobs is empty or contains an unsupported item type.
     */
    public function parallel(array $jobs): static
    {
        return $this->addParallelGroup(ParallelStepGroup::fromArray($jobs));
    }

    /**
     * Append a pre-built NestedPipeline to the pipeline.
     *
     * Symmetric with addStep() / addParallelGroup(): the nested wrapper
     * occupies one outer position in the pipeline's internal steps array.
     * Inner steps are NOT flattened; their sequential execution happens at
     * run time (SyncExecutor::executeNestedPipeline() in sync mode,
     * PipelineStepJob::handleNestedPipeline() with cursor-driven dispatch in
     * queued mode). Exposed publicly so advanced callers can hand-craft a
     * NestedPipeline via its factories and pass it through
     * addNestedPipeline() without going through the fluent nest() shortcut.
     *
     * @param NestedPipeline $nested Pre-built nested pipeline wrapping an inner PipelineDefinition.
     *
     * @return static
     */
    public function addNestedPipeline(NestedPipeline $nested): static
    {
        $this->steps[] = $nested;

        return $this;
    }

    /**
     * Append a nested sub-pipeline built from a PipelineBuilder or PipelineDefinition.
     *
     * Fluent shorthand for addNestedPipeline(NestedPipeline::from...($pipeline, $name)).
     * Conditions on nested pipelines are not supported in Story 8.2: apply
     * Step::when() / Step::unless() to individual inner steps. Per-step
     * mutators (compensateWith, onQueue, onConnection, sync, retry, backoff,
     * timeout) chained immediately after nest() throw InvalidPipelineDefinition
     * because the nested wrapper carries its own per-step configuration on
     * its inner PipelineDefinition; apply those mutators to individual inner
     * steps before wrapping.
     *
     * @param PipelineBuilder|PipelineDefinition $pipeline Inner pipeline to wrap; builder form is built eagerly at wrap time.
     * @param string|null $name Optional user-visible sub-pipeline name for observability; defaults to null.
     *
     * @return static
     *
     * @throws InvalidPipelineDefinition Propagated from PipelineBuilder::build() when called with a builder that has no steps.
     */
    public function nest(PipelineBuilder|PipelineDefinition $pipeline, ?string $name = null): static
    {
        if ($pipeline instanceof self) {
            return $this->addNestedPipeline(NestedPipeline::fromBuilder($pipeline, $name));
        }

        return $this->addNestedPipeline(NestedPipeline::fromDefinition($pipeline, $name));
    }

    /**
     * Append a pre-built ConditionalBranch to the pipeline.
     *
     * Symmetric with addStep() / addParallelGroup() / addNestedPipeline():
     * the branch wrapper occupies ONE outer position in the pipeline's
     * internal steps array. Inner branch values are NOT flattened; the
     * selector closure is evaluated at run time and ONE branch path runs
     * in place of the branch position. Exposed publicly so advanced callers
     * can hand-craft a ConditionalBranch via ConditionalBranch::fromArray()
     * and pass it through addConditionalBranch() without going through the
     * fluent branch() shortcut.
     *
     * @param ConditionalBranch $branch Pre-built conditional branch wrapping a selector and branch values.
     *
     * @return static
     */
    public function addConditionalBranch(ConditionalBranch $branch): static
    {
        $this->steps[] = $branch;

        return $this;
    }

    /**
     * Append a conditional branch group built from a selector closure and a branches map.
     *
     * Fluent shorthand for addConditionalBranch(ConditionalBranch::fromArray($selector, $branches, $name)).
     * Per-step mutators (compensateWith, onQueue, onConnection, sync, retry,
     * backoff, timeout) chained immediately after branch() throw
     * InvalidPipelineDefinition because each branch value carries its own
     * per-branch configuration on the underlying StepDefinition /
     * NestedPipeline; apply those mutators to individual branch values
     * before wrapping them via JobPipeline::branch([...]).
     *
     * Branches converge to the next outer step after the selected branch
     * completes (FR26, FR27). The selector is guaranteed to run EXACTLY
     * ONCE per branch traversal (sync inline, queued on the branch wrapper
     * before the next wrapper dispatches).
     *
     * @param Closure $selector Selector closure typed Closure(PipelineContext): string.
     * @param array<array-key, mixed> $branches Map of branch keys to step values (class-string, StepDefinition, NestedPipeline, PipelineBuilder, or PipelineDefinition).
     * @param string|null $name Optional user-visible branch name for observability; defaults to null.
     *
     * @return static
     *
     * @throws InvalidPipelineDefinition When $branches is empty, carries a blank key, contains a ParallelStepGroup, or contains an unsupported value type.
     */
    public function branch(Closure $selector, array $branches, ?string $name = null): static
    {
        return $this->addConditionalBranch(ConditionalBranch::fromArray($selector, $branches, $name));
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
        $lastStep = $this->requireLastIsStep(__FUNCTION__);

        if ($lastStep->compensationJobClass !== null) {
            $this->steps[] = $lastStep;

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

        $last = $this->requireLastIsStep(__FUNCTION__);
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

        $last = $this->requireLastIsStep(__FUNCTION__);
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
        $last = $this->requireLastIsStep(__FUNCTION__);
        $this->steps[] = $last->sync();

        return $this;
    }

    /**
     * Apply the given retry count to the last added step.
     *
     * Replaces the last StepDefinition with a new instance carrying the
     * retry override. The count is the number of RETRY attempts after the
     * initial attempt (a value of 3 means 4 total attempts). Last-write-wins
     * when called twice on the same step. For a pipeline-wide default, use
     * defaultRetry() instead. Retry is delivered via an in-process loop
     * inside both SyncExecutor and PipelineStepJob; the queued wrapper's
     * Laravel `$tries` remains locked to 1.
     *
     * @param int $retry Number of retry attempts after the initial attempt. Must be non-negative (0 means no retry).
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when $retry is negative.
     */
    public function retry(int $retry): static
    {
        if ($retry < 0) {
            throw new InvalidPipelineDefinition('retry must be a non-negative integer, got '.$retry.'.');
        }

        $last = $this->requireLastIsStep(__FUNCTION__);
        $this->steps[] = $last->retry($retry);

        return $this;
    }

    /**
     * Apply the given backoff delay to the last added step.
     *
     * Replaces the last StepDefinition with a new instance carrying the
     * backoff override. The value is the number of seconds to sleep between
     * retry attempts and is only consulted when retry is non-null and
     * greater than zero. Last-write-wins when called twice on the same
     * step. For a pipeline-wide default, use defaultBackoff() instead.
     *
     * @param int $backoff Seconds to sleep between retry attempts. Must be non-negative (0 means no sleep).
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when $backoff is negative.
     */
    public function backoff(int $backoff): static
    {
        if ($backoff < 0) {
            throw new InvalidPipelineDefinition('backoff must be a non-negative integer, got '.$backoff.'.');
        }

        $last = $this->requireLastIsStep(__FUNCTION__);
        $this->steps[] = $last->backoff($backoff);

        return $this;
    }

    /**
     * Apply the given wrapper timeout to the last added step.
     *
     * Replaces the last StepDefinition with a new instance carrying the
     * timeout override. The value is applied to the queued wrapper's public
     * `$timeout` property at dispatch time. Inert in synchronous and
     * recording execution modes (SyncExecutor and RecordingExecutor ignore
     * this value; the manifest still carries it for test observability).
     * Last-write-wins when called twice on the same step. For a
     * pipeline-wide default, use defaultTimeout() instead.
     *
     * @param int $timeout Maximum execution time in seconds for the queued wrapper. Must be greater than or equal to 1.
     * @return static
     *
     * @throws InvalidPipelineDefinition When called before any step has been added or when $timeout is less than 1.
     */
    public function timeout(int $timeout): static
    {
        if ($timeout < 1) {
            throw new InvalidPipelineDefinition('timeout must be a positive integer (>= 1), got '.$timeout.'.');
        }

        $last = $this->requireLastIsStep(__FUNCTION__);
        $this->steps[] = $last->timeout($timeout);

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
     * Declare the pipeline-level default retry count for steps without explicit retry().
     *
     * Unlike retry(), this method is valid to call before any step has been
     * added because pipeline-level defaults are pipeline-wide. Steps with
     * explicit retry() override the default. Last-write-wins when called
     * multiple times. Active in both synchronous and queued modes because
     * the retry loop runs in-process.
     *
     * @param int $retry Default retry attempts applied to steps without an explicit retry() override. Must be non-negative.
     * @return static
     *
     * @throws InvalidPipelineDefinition When $retry is negative.
     */
    public function defaultRetry(int $retry): static
    {
        if ($retry < 0) {
            throw new InvalidPipelineDefinition('retry must be a non-negative integer, got '.$retry.'.');
        }

        $this->defaultRetry = $retry;

        return $this;
    }

    /**
     * Declare the pipeline-level default backoff delay for steps without explicit backoff().
     *
     * Unlike backoff(), this method is valid to call before any step has
     * been added because pipeline-level defaults are pipeline-wide. Steps
     * with explicit backoff() override the default. Last-write-wins when
     * called multiple times. Only consulted when the resolved retry value
     * is non-null and greater than zero.
     *
     * @param int $backoff Default backoff (seconds) applied to steps without an explicit backoff() override. Must be non-negative.
     * @return static
     *
     * @throws InvalidPipelineDefinition When $backoff is negative.
     */
    public function defaultBackoff(int $backoff): static
    {
        if ($backoff < 0) {
            throw new InvalidPipelineDefinition('backoff must be a non-negative integer, got '.$backoff.'.');
        }

        $this->defaultBackoff = $backoff;

        return $this;
    }

    /**
     * Declare the pipeline-level default timeout for steps without explicit timeout().
     *
     * Unlike timeout(), this method is valid to call before any step has
     * been added. Steps with explicit timeout() override the default.
     * Last-write-wins when called multiple times. Inert in synchronous and
     * recording execution modes (SyncExecutor and RecordingExecutor ignore
     * the value; the manifest still carries it for test observability).
     *
     * @param int $timeout Default timeout (seconds) applied to steps without an explicit timeout() override. Must be greater than or equal to 1.
     * @return static
     *
     * @throws InvalidPipelineDefinition When $timeout is less than 1.
     */
    public function defaultTimeout(int $timeout): static
    {
        if ($timeout < 1) {
            throw new InvalidPipelineDefinition('timeout must be a positive integer (>= 1), got '.$timeout.'.');
        }

        $this->defaultTimeout = $timeout;

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
     * Opt in to Laravel event dispatch for the pipeline's lifecycle events.
     *
     * When enabled, the executors dispatch:
     *
     * - PipelineStepCompleted after every successful flat step completion
     *   (including parallel sub-steps, nested inner steps, and the selected
     *   branch's inner steps).
     * - PipelineStepFailed when a step throws (fires under ALL FailStrategy
     *   branches: StopImmediately, StopAndCompensate, SkipAndContinue).
     * - PipelineCompleted once per pipeline run at terminal exit on either
     *   success or failure tails (mirrors the onComplete() callback).
     *
     * Zero-overhead contract: when dispatchEvents() is NOT called, the
     * executor never constructs event objects or calls Event::dispatch().
     * The opt-in flag is the single point of decision, enforced by the
     * centralized PipelineEventDispatcher helper.
     *
     * Idempotent: calling dispatchEvents() twice has no additional effect.
     * The flag is a simple boolean, not an accumulator, so last-write-wins
     * is equivalent to first-write-wins for this particular setter.
     *
     * Events dispatch independently of the orthogonal onSuccess / onFailure
     * / onComplete pipeline-level callbacks (Story 6.2) and the beforeEach
     * / afterEach / onStepFailed per-step hooks (Story 6.1). A user
     * registering both hooks AND events gets both signals: hooks fire
     * first in-process, events fire through Laravel's event dispatcher.
     *
     * CompensationFailed (operational alerting) is NOT gated by this flag;
     * it continues to fire unconditionally on compensation failure.
     *
     * @return static
     */
    public function dispatchEvents(): static
    {
        $this->dispatchEvents = true;

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
     * Return a new PipelineBuilder whose outer-position steps are the receiver's steps in reverse order.
     *
     * Rationale and scope:
     * - reverse() is a construction helper, NOT a separate execution mode. It
     *   produces a DISTINCT PipelineBuilder instance whose internal $steps
     *   array is the reverse of the receiver's, and whose every other
     *   pipeline-level field is copied verbatim. The receiver is never
     *   mutated: its $steps array and its observable build() output remain
     *   identical to what they would have been without the reverse() call.
     * - The returned builder produces its own PipelineDefinition on build();
     *   all existing executors (SyncExecutor, QueuedExecutor, RecordingExecutor)
     *   consume that reversed definition identically to any non-reversed one.
     *   No new executor, no new manifest shape, no new queued wrapper, no new
     *   event are introduced by reversal.
     *
     * Outer-position-only reversal:
     * - ParallelStepGroup, NestedPipeline, and ConditionalBranch entries each
     *   occupy ONE outer position and are reversed alongside flat
     *   StepDefinition entries at that outer level; their inner contents are
     *   preserved verbatim.
     * - Parallel sub-steps are conceptually concurrent, so an inner reversal
     *   would be semantically meaningless.
     * - Nested inner pipelines are reusable, self-contained units; recursive
     *   reversal would make reverse() non-composable. Users needing reversed
     *   inner order must call reverse() on the inner builder BEFORE wrapping
     *   it via ->nest(...).
     * - Conditional branches are a key-indexed map, not an ordered sequence;
     *   the concept of "reverse" does not apply to their branches map.
     *
     * Pipeline-level state propagation:
     * - Every non-step field transfers to the new builder by shallow-copy:
     *   stored context or context closure (send()), queued flag
     *   (shouldBeQueued()), dispatch-events flag (dispatchEvents()), return
     *   callback (return()), fail strategy and failure callback (onFailure()),
     *   pipeline-level defaults (defaultQueue, defaultConnection,
     *   defaultRetry, defaultBackoff, defaultTimeout), hook arrays
     *   (beforeEachHooks, afterEachHooks, onStepFailedHooks), and callback
     *   slots (onSuccessCallback, onFailureCallback, onCompleteCallback).
     *
     * Idempotency and independence:
     * - Period-2 idempotent: reverse()->reverse() yields a distinct NEW builder
     *   whose steps array is equal to the receiver's by value (same step
     *   objects captured by reference; StepDefinition / ParallelStepGroup /
     *   NestedPipeline / ConditionalBranch are all immutable value objects).
     * - Independence after split: mutations applied to the ORIGINAL builder
     *   after reverse() returns (e.g., adding a further ->step(...),
     *   registering an additional beforeEach hook) do NOT affect the reversed
     *   builder's subsequent build() output, and vice versa. PHP array
     *   copy-on-write gives each builder its own branch of the step and hook
     *   arrays once either side mutates. Closures and readonly value objects
     *   are shared by reference but neither can be mutated, so sharing is
     *   safe.
     *
     * Edge cases:
     * - Empty receiver: reverse() may be called on a builder whose $steps is
     *   empty; the returned builder is also empty. Building either builder
     *   throws InvalidPipelineDefinition::emptySteps() at build() time per
     *   the existing contract; no new exception is introduced.
     * - Single-step receiver: [A]->reverse()->build()->steps equals [A] by
     *   value; identity check from @see AC #2 still requires a NEW builder
     *   instance.
     *
     * Condition and compensation preservation:
     * - Steps carrying when() / unless() closures retain their condition
     *   after reversal; the closure fires at runtime against the live context
     *   at the step's NEW position.
     * - Steps carrying compensateWith(...) retain their compensation class;
     *   when that step's compensation eventually runs under StopAndCompensate,
     *   it runs in the reverse order of the REVERSED execution (not the
     *   original declaration order) because the compensation chain follows
     *   the execution-order completedSteps list.
     *
     * @return static A NEW PipelineBuilder instance with outer-position steps reversed and every pipeline-level field copied verbatim.
     *
     * @see PipelineBuilder::build() for the downstream PipelineDefinition contract consumed by all executors.
     */
    public function reverse(): static
    {
        $clone = new self;
        $clone->steps = array_reverse($this->steps);
        $clone->context = $this->context;
        $clone->shouldBeQueued = $this->shouldBeQueued;
        $clone->dispatchEvents = $this->dispatchEvents;
        $clone->returnCallback = $this->returnCallback;
        $clone->failStrategy = $this->failStrategy;
        $clone->defaultQueue = $this->defaultQueue;
        $clone->defaultConnection = $this->defaultConnection;
        $clone->defaultRetry = $this->defaultRetry;
        $clone->defaultBackoff = $this->defaultBackoff;
        $clone->defaultTimeout = $this->defaultTimeout;
        $clone->beforeEachHooks = $this->beforeEachHooks;
        $clone->afterEachHooks = $this->afterEachHooks;
        $clone->onStepFailedHooks = $this->onStepFailedHooks;
        $clone->onSuccessCallback = $this->onSuccessCallback;
        $clone->onFailureCallback = $this->onFailureCallback;
        $clone->onCompleteCallback = $this->onCompleteCallback;

        return $clone;
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
            defaultRetry: $this->defaultRetry,
            defaultBackoff: $this->defaultBackoff,
            defaultTimeout: $this->defaultTimeout,
            dispatchEvents: $this->dispatchEvents,
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

        $stepClasses = self::buildStepClassesPayload($definition);

        $stepConfigs = self::resolveStepConfigs($definition);

        $manifest = PipelineManifest::create(
            stepClasses: $stepClasses,
            context: $resolvedContext,
            compensationMapping: $definition->compensationMapping(),
            stepConditions: $this->buildStepConditions($definition),
            failStrategy: $definition->failStrategy,
            stepConfigs: $stepConfigs,
            dispatchEvents: $definition->dispatchEvents,
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

        $stepClasses = self::buildStepClassesPayload($definition);

        return function (object $event) use ($definition, $contextSource, $shouldBeQueued, $stepClasses, $stepConditions, $stepConfigs, $hookClosures, $callbackClosures): void {
            $resolvedContext = $contextSource instanceof Closure
                ? ($contextSource)($event)
                : $contextSource;

            $manifest = PipelineManifest::create(
                stepClasses: $stepClasses,
                context: $resolvedContext,
                compensationMapping: $definition->compensationMapping(),
                stepConditions: $stepConditions,
                failStrategy: $definition->failStrategy,
                stepConfigs: $stepConfigs,
                dispatchEvents: $definition->dispatchEvents,
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
     * ParallelStepGroup entries produce a nested shape keyed by the outer
     * position: `['type' => 'parallel', 'entries' => array<int, entry|null>]`
     * where each inner entry mirrors the non-parallel shape (closure +
     * negated) OR is null when the sub-step carries no condition. This
     * preserves positional alignment with sub-steps so condition lookup
     * at execution time is a direct index into `['entries'][$subIndex]`.
     *
     * NestedPipeline entries produce the symmetric nested shape
     * `['type' => 'nested', 'entries' => array<int, entry|null|array>]`
     * where each inner entry is either a flat condition shape, null (for
     * unconditional inner steps), a parallel shape (for inner parallel
     * sub-groups), or another nested shape (for inner nested sub-pipelines).
     * Recursion is delegated to buildNestedStepConditionsPayload().
     *
     * @param PipelineDefinition $definition The built pipeline definition.
     *
     * @return array<int, array<string, mixed>> Condition entries keyed by outer step index; parallel and nested entries carry discriminator-tagged arrays whose full recursive shape matches the buildStepClassesPayload tree.
     */
    private function buildStepConditions(PipelineDefinition $definition): array
    {
        $conditions = [];

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
                    $conditions[$index] = [
                        'type' => 'parallel',
                        'entries' => $entries,
                    ];
                }
                // Intentionally omit $conditions[$index] when $hasAny === false:
                // consumers read via $stepConditions[$index] ?? null and treat
                // absence as "unconditional group" (keeps the manifest payload
                // lean when no sub-step carries a condition).

                continue;
            }

            if ($step instanceof NestedPipeline) {
                $nestedPayload = $this->buildNestedStepConditionsPayload($step);

                if ($nestedPayload !== null) {
                    $conditions[$index] = $nestedPayload;
                }

                continue;
            }

            if ($step instanceof ConditionalBranch) {
                $branchPayload = $this->buildConditionalBranchStepConditionsPayload($step);

                if ($branchPayload !== null) {
                    $conditions[$index] = $branchPayload;
                }

                continue;
            }

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
     * Recursively build the branch-shape condition payload for a ConditionalBranch entry.
     *
     * Walks the branches map and emits one entry per branch key: null for
     * unconditional flat StepDefinition values, the flat `{closure, negated}`
     * shape for conditional StepDefinition values, or the nested shape for
     * NestedPipeline values (delegating to buildNestedStepConditionsPayload()).
     * Returns null (so the caller can omit the outer-index key from the
     * manifest) when no branch entry carries any condition at any level
     * — same lean-payload policy as the nested group.
     *
     * @param ConditionalBranch $branch The branch wrapper whose branches' conditions are walked.
     *
     * @return array{type: string, entries: array<string, array<string, mixed>|null>}|null The branch discriminator-tagged condition payload, or null when no branch carries any condition.
     */
    private function buildConditionalBranchStepConditionsPayload(ConditionalBranch $branch): ?array
    {
        $entries = [];
        $hasAny = false;

        foreach ($branch->branches as $key => $value) {
            if ($value instanceof NestedPipeline) {
                $nestedPayload = $this->buildNestedStepConditionsPayload($value);

                if ($nestedPayload !== null) {
                    $entries[$key] = $nestedPayload;
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
     * Walks the inner definition's $steps and emits one entry per inner
     * position: flat condition shape for StepDefinition (null when
     * unconditional), a parallel shape for ParallelStepGroup sub-groups, or
     * another nested shape for NestedPipeline sub-entries. Returns null
     * (so the caller can omit the outer-index key from the manifest) when
     * no inner entry carries a condition AND no inner sub-group contributes
     * any condition either, keeping the manifest payload lean for
     * unconditional nested groups (mirrors the parallel-group lean-payload
     * policy).
     *
     * @param NestedPipeline $nested The nested wrapper whose inner definition's conditions are walked.
     *
     * @return array{type: string, entries: array<int, array<string, mixed>|null>}|null The nested discriminator-tagged payload, or null when the nested group carries no conditions at any level.
     */
    private function buildNestedStepConditionsPayload(NestedPipeline $nested): ?array
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
                $innerNested = $this->buildNestedStepConditionsPayload($subStep);

                if ($innerNested !== null) {
                    $entries[$subIndex] = $innerNested;
                    $hasAny = true;
                } else {
                    $entries[$subIndex] = null;
                }

                continue;
            }

            if ($subStep instanceof ConditionalBranch) {
                $innerBranch = $this->buildConditionalBranchStepConditionsPayload($subStep);

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
     * Iterates the definition's steps and resolves queue / connection / sync /
     * retry / backoff / timeout values following the precedence rule
     * `step override > pipeline default > null`. Sync has no pipeline-level
     * default; the step's own sync flag is carried verbatim.
     *
     * Called once at manifest creation time in run() and toListener() so
     * executors consume fully-resolved values without re-running precedence
     * logic at every dispatch point. Reads the pipeline-level defaults from
     * the definition so external consumers of the definition can reproduce
     * the same resolution without access to the builder.
     *
     * ParallelStepGroup entries produce a nested shape at their outer
     * position: `['type' => 'parallel', 'configs' => array<int, inner>]`
     * where each inner entry resolves the same step-override-over-default
     * precedence for a sub-step. NestedPipeline entries produce the
     * symmetric `['type' => 'nested', 'configs' => array<int, inner|array>]`
     * shape via resolveNestedStepConfigs(); inner parallel sub-groups and
     * nested sub-pipelines recurse. Non-parallel / non-nested entries keep
     * their flat config shape.
     *
     * @param PipelineDefinition $definition The built pipeline definition carrying steps and pipeline-level defaults.
     *
     * @return array<int, array<string, mixed>> Resolved per-step config indexed by step position; parallel and nested entries carry discriminator-tagged arrays whose recursive shape matches the buildStepClassesPayload tree.
     */
    public static function resolveStepConfigs(PipelineDefinition $definition): array
    {
        $configs = [];

        foreach ($definition->steps as $index => $step) {
            if ($step instanceof ParallelStepGroup) {
                $subConfigs = [];

                foreach ($step->steps as $subIndex => $subStep) {
                    $subConfigs[$subIndex] = self::resolveStepConfig($subStep, $definition);
                }

                $configs[$index] = [
                    'type' => 'parallel',
                    'configs' => $subConfigs,
                ];

                continue;
            }

            if ($step instanceof NestedPipeline) {
                $configs[$index] = self::resolveNestedStepConfigs($step);

                continue;
            }

            if ($step instanceof ConditionalBranch) {
                $configs[$index] = self::resolveConditionalBranchStepConfigs($step, $definition);

                continue;
            }

            $configs[$index] = self::resolveStepConfig($step, $definition);
        }

        return $configs;
    }

    /**
     * Recursively resolve the branch-shape step-configs payload for a ConditionalBranch entry.
     *
     * Walks the branches map and emits one config entry per branch key: a
     * flat step config for StepDefinition branch values (resolved against
     * the OUTER pipeline's defaults because the flat value executes at the
     * outer branch position), or a nested shape for NestedPipeline branch
     * values (delegating to resolveNestedStepConfigs(), which uses the
     * INNER definition's own defaults).
     *
     * Flat StepDefinition branch values resolve against the OUTER
     * PipelineDefinition's pipeline-level defaults because they execute at
     * the outer branch position; NestedPipeline branch values delegate to
     * their inner PipelineDefinition's own defaults.
     *
     * @param ConditionalBranch $branch The branch wrapper whose branches' configs are resolved.
     * @param PipelineDefinition $definition The OUTER pipeline definition carrying pipeline-level defaults for flat branch values.
     *
     * @return array{type: string, configs: array<string, array<string, mixed>>} The branch discriminator-tagged configs payload.
     */
    private static function resolveConditionalBranchStepConfigs(ConditionalBranch $branch, PipelineDefinition $definition): array
    {
        $configs = [];

        foreach ($branch->branches as $key => $value) {
            if ($value instanceof NestedPipeline) {
                $configs[$key] = self::resolveNestedStepConfigs($value);

                continue;
            }

            $configs[$key] = self::resolveStepConfig($value, $definition);
        }

        return [
            'type' => 'branch',
            'configs' => $configs,
        ];
    }

    /**
     * Recursively resolve the nested-shape step configs payload for a NestedPipeline entry.
     *
     * The INNER PipelineDefinition's own defaults (defaultQueue,
     * defaultConnection, defaultRetry, defaultBackoff, defaultTimeout)
     * drive inner-step resolution. Outer-pipeline defaults do NOT cascade
     * into inner sub-steps: nested pipelines are self-contained reusable
     * units whose default configuration ships with them. Inner parallel
     * sub-groups and inner nested sub-pipelines recurse through the same
     * helper at their respective level.
     *
     * @param NestedPipeline $nested The nested wrapper whose inner definition's configs are resolved.
     *
     * @return array{type: string, configs: array<int, array<string, mixed>>} The nested discriminator-tagged configs payload.
     */
    private static function resolveNestedStepConfigs(NestedPipeline $nested): array
    {
        $innerConfigs = [];

        foreach ($nested->definition->steps as $subIndex => $subStep) {
            if ($subStep instanceof ParallelStepGroup) {
                $parallelConfigs = [];

                foreach ($subStep->steps as $grandSubIndex => $grandSubStep) {
                    $parallelConfigs[$grandSubIndex] = self::resolveStepConfig($grandSubStep, $nested->definition);
                }

                $innerConfigs[$subIndex] = [
                    'type' => 'parallel',
                    'configs' => $parallelConfigs,
                ];

                continue;
            }

            if ($subStep instanceof NestedPipeline) {
                $innerConfigs[$subIndex] = self::resolveNestedStepConfigs($subStep);

                continue;
            }

            if ($subStep instanceof ConditionalBranch) {
                $innerConfigs[$subIndex] = self::resolveConditionalBranchStepConfigs($subStep, $nested->definition);

                continue;
            }

            $innerConfigs[$subIndex] = self::resolveStepConfig($subStep, $nested->definition);
        }

        return [
            'type' => 'nested',
            'configs' => $innerConfigs,
        ];
    }

    /**
     * Resolve the effective execution configuration for a single StepDefinition.
     *
     * Applies the `step override > pipeline default > null` precedence
     * rule for queue, connection, retry, backoff, and timeout; sync is
     * pass-through because there is no pipeline-level sync default.
     * Extracted from resolveStepConfigs() so both the flat and nested
     * (parallel-group) code paths share the same resolution.
     *
     * @param StepDefinition $step The sub-step to resolve configuration for.
     * @param PipelineDefinition $definition The enclosing definition carrying pipeline-level defaults.
     *
     * @return array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} The fully resolved per-step configuration.
     */
    private static function resolveStepConfig(StepDefinition $step, PipelineDefinition $definition): array
    {
        return [
            'queue' => $step->queue ?? $definition->defaultQueue,
            'connection' => $step->connection ?? $definition->defaultConnection,
            'sync' => $step->sync,
            'retry' => $step->retry ?? $definition->defaultRetry,
            'backoff' => $step->backoff ?? $definition->defaultBackoff,
            'timeout' => $step->timeout ?? $definition->defaultTimeout,
        ];
    }

    /**
     * Build the widened step-classes payload for the manifest.
     *
     * Shared helper between run() and toListener(). Non-parallel / non-nested
     * outer positions contribute a plain class-string; ParallelStepGroup
     * positions contribute `['type' => 'parallel', 'classes' => [...]]`;
     * NestedPipeline positions contribute
     * `['type' => 'nested', 'name' => ?string, 'steps' => [...]]` where the
     * inner `steps` array is built recursively via
     * buildNestedStepClassesPayload() and may itself contain further
     * parallel or nested shapes.
     *
     * @param PipelineDefinition $definition The built pipeline definition.
     *
     * @return array<int, string|array<string, mixed>> Outer-position-indexed step-classes payload. Parallel and nested entries carry discriminator-tagged arrays; the full recursive shape is documented in the NestedPipeline class PHPDoc.
     */
    private static function buildStepClassesPayload(PipelineDefinition $definition): array
    {
        $payload = [];

        foreach ($definition->steps as $index => $step) {
            if ($step instanceof ParallelStepGroup) {
                $payload[$index] = [
                    'type' => 'parallel',
                    'classes' => array_map(
                        static fn (StepDefinition $subStep): string => $subStep->jobClass,
                        $step->steps,
                    ),
                ];

                continue;
            }

            if ($step instanceof NestedPipeline) {
                $payload[$index] = self::buildNestedStepClassesPayload($step);

                continue;
            }

            if ($step instanceof ConditionalBranch) {
                $payload[$index] = self::buildConditionalBranchStepClassesPayload($step);

                continue;
            }

            $payload[$index] = $step->jobClass;
        }

        return $payload;
    }

    /**
     * Recursively build the branch-shape step-classes payload for a ConditionalBranch entry.
     *
     * Produces `['type' => 'branch', 'name' => ?string, 'selector' => SerializableClosure, 'branches' => [...]]`
     * where each branch entry is either a class-string (for a flat
     * StepDefinition branch value) or a nested shape (for a NestedPipeline
     * branch value, delegated to buildNestedStepClassesPayload()).
     *
     * The selector closure is wrapped via SerializableClosure at payload-
     * build time so the manifest survives the queue boundary (mirrors the
     * condition-wrap pattern for step conditions).
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
     * Recursively build the nested-shape step-classes payload for a NestedPipeline entry.
     *
     * Produces `['type' => 'nested', 'name' => ?string, 'steps' => [...]]`
     * where each inner entry is either a class-string (for a flat
     * StepDefinition), a parallel shape (for a ParallelStepGroup sub-step),
     * or another nested shape (for a NestedPipeline sub-step). The nested
     * case recurses through this same helper, supporting arbitrary-depth
     * composition.
     *
     * @param NestedPipeline $nested The nested wrapper whose inner definition's steps are walked.
     *
     * @return array{type: string, name: ?string, steps: array<int, string|array<string, mixed>>} The nested discriminator-tagged shape.
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
     * Enforce that the last accumulated step entry is a mutable StepDefinition.
     *
     * Pops the last element of the internal $steps array and verifies the
     * preconditions before returning it:
     *  - the array is non-empty (the caller has at least one step),
     *  - the popped entry is a StepDefinition (not a ParallelStepGroup or
     *    NestedPipeline).
     *
     * On any precondition failure the method throws
     * InvalidPipelineDefinition with a targeted message naming the caller
     * (via __FUNCTION__ passed in by the caller). On ParallelStepGroup or
     * NestedPipeline rejection the popped group is restored so the
     * builder's state is left intact for callers handling the exception.
     * The caller is expected to re-append the returned StepDefinition
     * (typically in a mutated form via StepDefinition::onQueue() / retry()
     * / etc.).
     *
     * @param string $methodName The calling mutator's method name (e.g., 'onQueue'); surfaces in the thrown error message.
     *
     * @return StepDefinition The last StepDefinition popped off the accumulator.
     *
     * @throws InvalidPipelineDefinition When the accumulator is empty or the last entry is a ParallelStepGroup, NestedPipeline, or ConditionalBranch.
     */
    private function requireLastIsStep(string $methodName): StepDefinition
    {
        if ($this->steps === []) {
            throw new InvalidPipelineDefinition(
                "Cannot call {$methodName}() on PipelineBuilder before adding a step. Chain {$methodName}() after step() or addStep().",
            );
        }

        $last = array_pop($this->steps);

        if ($last instanceof ParallelStepGroup) {
            $this->steps[] = $last;

            throw new InvalidPipelineDefinition(
                "Cannot call {$methodName}() on a parallel step group. Apply {$methodName}() to individual steps before wrapping them into JobPipeline::parallel([...]).",
            );
        }

        if ($last instanceof NestedPipeline) {
            $this->steps[] = $last;

            throw new InvalidPipelineDefinition(
                "Cannot call {$methodName}() on a nested pipeline group. Apply {$methodName}() to individual steps inside the sub-pipeline before wrapping it via JobPipeline::nest([...]).",
            );
        }

        if ($last instanceof ConditionalBranch) {
            $this->steps[] = $last;

            throw new InvalidPipelineDefinition(
                "Cannot call {$methodName}() on a conditional branch. Apply {$methodName}() to individual steps inside each branch value before wrapping them via JobPipeline::branch(\$selector, [...]).",
            );
        }

        return $last;
    }
}
