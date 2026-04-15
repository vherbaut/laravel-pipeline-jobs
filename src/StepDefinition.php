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

    /**
     * Return a new step definition with the given retry count applied.
     *
     * Immutable with-style: every other field is preserved bit-for-bit from
     * the original instance. The count represents the number of RETRY
     * attempts after the initial attempt (a value of 3 means 4 total
     * attempts: 1 initial + 3 retries). Non-negative validation is the
     * PipelineBuilder's responsibility.
     *
     * Zero semantics: `retry(0)` means "no retry" and has two distinct
     * effects depending on pipeline-level defaults. When no `defaultRetry`
     * is declared on the pipeline, `retry(0)` is equivalent to not calling
     * this method at all (both resolve to null). When a non-null
     * `defaultRetry(N)` IS declared, `retry(0)` explicitly OVERRIDES the
     * pipeline default to opt this step out of retrying — `0 !== null`, so
     * the manifest resolution `$step->retry ?? $definition->defaultRetry`
     * preserves the 0 rather than falling through to the default.
     *
     * @param int $retry Number of retry attempts after the initial attempt (0 means no retry; explicitly overrides a pipeline-level defaultRetry).
     *
     * @return self A new StepDefinition with the retry override applied.
     */
    public function retry(int $retry): self
    {
        return new self(
            jobClass: $this->jobClass,
            compensationJobClass: $this->compensationJobClass,
            condition: $this->condition,
            conditionNegated: $this->conditionNegated,
            queue: $this->queue,
            connection: $this->connection,
            retry: $retry,
            backoff: $this->backoff,
            timeout: $this->timeout,
            sync: $this->sync,
        );
    }

    /**
     * Return a new step definition with the given backoff delay applied.
     *
     * Immutable with-style: every other field is preserved bit-for-bit. The
     * value is the number of seconds to sleep between retry attempts and is
     * only consulted when retry is non-null and greater than zero.
     *
     * @param int $backoff Seconds to sleep between retry attempts (0 means no sleep).
     *
     * @return self A new StepDefinition with the backoff override applied.
     */
    public function backoff(int $backoff): self
    {
        return new self(
            jobClass: $this->jobClass,
            compensationJobClass: $this->compensationJobClass,
            condition: $this->condition,
            conditionNegated: $this->conditionNegated,
            queue: $this->queue,
            connection: $this->connection,
            retry: $this->retry,
            backoff: $backoff,
            timeout: $this->timeout,
            sync: $this->sync,
        );
    }

    /**
     * Return a new step definition with the given wrapper timeout applied.
     *
     * Immutable with-style: every other field is preserved bit-for-bit. The
     * value is the maximum execution time in seconds applied to the queued
     * wrapper via its public `$timeout` property. Inert in synchronous and
     * recording execution modes (see SyncExecutor and RecordingExecutor
     * class-level PHPDoc).
     *
     * @param int $timeout Maximum execution time in seconds for the queued wrapper.
     *
     * @return self A new StepDefinition with the timeout override applied.
     */
    public function timeout(int $timeout): self
    {
        return new self(
            jobClass: $this->jobClass,
            compensationJobClass: $this->compensationJobClass,
            condition: $this->condition,
            conditionNegated: $this->conditionNegated,
            queue: $this->queue,
            connection: $this->connection,
            retry: $this->retry,
            backoff: $this->backoff,
            timeout: $timeout,
            sync: $this->sync,
        );
    }
}
