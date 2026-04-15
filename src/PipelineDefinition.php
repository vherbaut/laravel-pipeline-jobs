<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;

/**
 * Immutable value object representing a complete pipeline description.
 *
 * Contains the ordered list of steps, pipeline-level configuration,
 * and hook registrations. Produced by PipelineBuilder and consumed
 * by the executor. Never serialized; lives in memory only.
 */
final class PipelineDefinition
{
    /**
     * Create a new pipeline definition.
     *
     * @param array<int, StepDefinition> $steps Ordered list of step definitions (must not be empty).
     * @param bool $shouldBeQueued Whether the pipeline should be dispatched to the queue.
     * @param string|null $name Optional human-readable pipeline name.
     * @param array<int, Closure> $beforeEachHooks Closures to run before each step.
     * @param array<int, Closure> $afterEachHooks Closures to run after each step.
     * @param array<int, Closure> $onStepFailedHooks Closures to run when a step fails.
     * @param Closure|null $onComplete Closure to run when the pipeline completes (success or failure).
     * @param Closure|null $onSuccess Closure to run when the pipeline completes successfully.
     * @param Closure|null $onFailure Closure to run when the pipeline fails.
     * @param FailStrategy $failStrategy Saga failure strategy driving executor behavior when a step fails.
     * @param string|null $defaultQueue Pipeline-level default queue name inherited by steps without an explicit onQueue() override.
     * @param string|null $defaultConnection Pipeline-level default queue connection inherited by steps without an explicit onConnection() override.
     *
     * @throws InvalidPipelineDefinition When the steps array is empty.
     */
    public function __construct(
        public readonly array $steps,
        public readonly bool $shouldBeQueued = false,
        public readonly ?string $name = null,
        public readonly array $beforeEachHooks = [],
        public readonly array $afterEachHooks = [],
        public readonly array $onStepFailedHooks = [],
        public readonly ?Closure $onComplete = null,
        public readonly ?Closure $onSuccess = null,
        public readonly ?Closure $onFailure = null,
        public readonly FailStrategy $failStrategy = FailStrategy::StopImmediately,
        public readonly ?string $defaultQueue = null,
        public readonly ?string $defaultConnection = null,
    ) {
        if ($this->steps === []) {
            throw InvalidPipelineDefinition::emptySteps();
        }
    }

    /**
     * Get the number of steps in this pipeline.
     *
     * @return int
     */
    public function stepCount(): int
    {
        return count($this->steps);
    }

    /**
     * Build a compensation mapping from the pipeline steps.
     *
     * @return array<string, string> Map of step class name to compensation class name.
     */
    public function compensationMapping(): array
    {
        $mapping = [];

        foreach ($this->steps as $step) {
            if ($step->compensationJobClass !== null) {
                $mapping[$step->jobClass] = $step->compensationJobClass;
            }
        }

        return $mapping;
    }
}
