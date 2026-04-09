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
}
