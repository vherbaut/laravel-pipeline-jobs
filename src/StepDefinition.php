<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;

/**
 * Immutable value object representing a single step in a pipeline definition.
 *
 * Holds the fully resolved configuration for one pipeline step,
 * including the job class, optional compensation, conditional execution,
 * queue targeting, and retry/timeout policies. All properties are readonly
 * to enforce immutability after construction.
 */
final class StepDefinition
{
    /**
     * Create a new step definition.
     *
     * @param string $jobClass Fully qualified class name of the job to execute.
     * @param string|null $compensationJobClass Fully qualified class name of the compensation job for saga rollback.
     * @param Closure|null $condition Closure that evaluates against PipelineContext to determine if this step should run.
     * @param bool $conditionNegated Whether the condition result should be inverted (true for unless, false for when).
     * @param string|null $queue Queue name for this step (overrides pipeline default).
     * @param string|null $connection Queue connection for this step (overrides pipeline default).
     * @param int|null $retry Number of retry attempts for this step.
     * @param int|null $backoff Backoff delay in seconds between retry attempts.
     * @param int|null $timeout Maximum execution time in seconds for this step.
     * @param bool $sync Whether to force synchronous execution for this step.
     */
    public function __construct(
        public readonly string $jobClass,
        public readonly ?string $compensationJobClass = null,
        public readonly ?Closure $condition = null,
        public readonly bool $conditionNegated = false,
        public readonly ?string $queue = null,
        public readonly ?string $connection = null,
        public readonly ?int $retry = null,
        public readonly ?int $backoff = null,
        public readonly ?int $timeout = null,
        public readonly bool $sync = false,
    ) {}

    /**
     * Create a minimal step definition from a job class name.
     *
     * @param string $jobClass Fully qualified class name of the job to execute.
     *
     * @return self
     */
    public static function fromJobClass(string $jobClass): self
    {
        return new self(jobClass: $jobClass);
    }

    /**
     * Return a new step definition with the given queue name applied.
     *
     * Immutable with-style: every other field is preserved bit-for-bit from
     * the original instance. An empty string is accepted at build time; a
     * zero-length queue name would fail at dispatch inside Laravel's queue
     * driver, which is the right layer for that error.
     *
     * @param string $queue Queue name to route this step's wrapper dispatch to.
     *
     * @return self A new StepDefinition with the queue override applied.
     */
    public function onQueue(string $queue): self
    {
        return new self(
            jobClass: $this->jobClass,
            compensationJobClass: $this->compensationJobClass,
            condition: $this->condition,
            conditionNegated: $this->conditionNegated,
            queue: $queue,
            connection: $this->connection,
            retry: $this->retry,
            backoff: $this->backoff,
            timeout: $this->timeout,
            sync: $this->sync,
        );
    }

    /**
     * Return a new step definition with the given connection name applied.
     *
     * Immutable with-style: every other field is preserved bit-for-bit from
     * the original instance.
     *
     * @param string $connection Queue connection name to route this step's wrapper dispatch to.
     *
     * @return self A new StepDefinition with the connection override applied.
     */
    public function onConnection(string $connection): self
    {
        return new self(
            jobClass: $this->jobClass,
            compensationJobClass: $this->compensationJobClass,
            condition: $this->condition,
            conditionNegated: $this->conditionNegated,
            queue: $this->queue,
            connection: $connection,
            retry: $this->retry,
            backoff: $this->backoff,
            timeout: $this->timeout,
            sync: $this->sync,
        );
    }

    /**
     * Return a new step definition forcing inline synchronous execution.
     *
     * This is NOT the Laravel "sync" queue driver. It forces the step to
     * run inline in the current PHP process via `dispatch_sync()` when the
     * enclosing pipeline is queued. Under `dispatch_sync()`, Laravel itself
     * overwrites the job's connection to the literal string `'sync'`
     * regardless of any previous `onConnection()` override; queue and
     * connection overrides therefore have no meaning on a sync step. This
     * method resets both `queue` and `connection` to null to keep the
     * StepDefinition free of dead data.
     *
     * Inert when the pipeline runs synchronously (`->run()` without
     * `->shouldBeQueued()`): the SyncExecutor runs every step inline
     * regardless of the sync flag. Only consulted by QueuedExecutor and
     * PipelineStepJob when the enclosing pipeline is queued.
     *
     * @return self A new StepDefinition with sync set to true and queue/connection cleared.
     */
    public function sync(): self
    {
        return new self(
            jobClass: $this->jobClass,
            compensationJobClass: $this->compensationJobClass,
            condition: $this->condition,
            conditionNegated: $this->conditionNegated,
            queue: null,
            connection: null,
            retry: $this->retry,
            backoff: $this->backoff,
            timeout: $this->timeout,
            sync: true,
        );
    }

    /**
     * Return a new step definition carrying the given compensation job class.
     *
     * Immutable with-style: every other field is preserved bit-for-bit. Used
     * by PipelineBuilder::compensateWith() so a new StepDefinition field
     * (future retry / backoff / timeout semantics) is picked up without
     * requiring every call site to be updated.
     *
     * @param string $compensationJobClass Fully qualified class name of the compensation job for saga rollback.
     *
     * @return self A new StepDefinition with the compensation job class applied.
     */
    public function withCompensation(string $compensationJobClass): self
    {
        return new self(
            jobClass: $this->jobClass,
            compensationJobClass: $compensationJobClass,
            condition: $this->condition,
            conditionNegated: $this->conditionNegated,
            queue: $this->queue,
            connection: $this->connection,
            retry: $this->retry,
            backoff: $this->backoff,
            timeout: $this->timeout,
            sync: $this->sync,
        );
    }
}
