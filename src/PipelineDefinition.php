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
     * @param array<int, StepDefinition|ParallelStepGroup|NestedPipeline|ConditionalBranch> $steps Ordered list of step definitions (must not be empty). Parallel groups and nested pipelines each occupy a single outer position.
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
     * @param int|null $defaultRetry Pipeline-level default retry count inherited by steps without an explicit retry() override.
     * @param int|null $defaultBackoff Pipeline-level default backoff delay (seconds) inherited by steps without an explicit backoff() override.
     * @param int|null $defaultTimeout Pipeline-level default timeout (seconds) inherited by steps without an explicit timeout() override.
     * @param bool $dispatchEvents Opt-in flag driving PipelineStepCompleted / PipelineStepFailed / PipelineCompleted dispatch during execution. Defaults to false so pipelines pay zero event-dispatch overhead unless the user explicitly calls PipelineBuilder::dispatchEvents().
     * @param RateLimitPolicy|null $rateLimitPolicy Optional pipeline-level rate-limit policy evaluated by PipelineRateLimiter::gate() at admission time. Defaults to null so unconfigured pipelines pay zero overhead.
     * @param ConcurrencyPolicy|null $concurrencyPolicy Optional pipeline-level concurrency-limit policy evaluated by PipelineConcurrencyGate::acquire() at admission time. Defaults to null so unconfigured pipelines pay zero overhead.
     *
     * @throws InvalidPipelineDefinition When the steps array is empty.
     */
    public function __construct(
        /** @var array<int, StepDefinition|ParallelStepGroup|NestedPipeline|ConditionalBranch> */
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
        public readonly ?int $defaultRetry = null,
        public readonly ?int $defaultBackoff = null,
        public readonly ?int $defaultTimeout = null,
        public readonly bool $dispatchEvents = false,
        public readonly ?RateLimitPolicy $rateLimitPolicy = null,
        public readonly ?ConcurrencyPolicy $concurrencyPolicy = null,
    ) {
        if ($this->steps === []) {
            throw InvalidPipelineDefinition::emptySteps();
        }
    }

    /**
     * Get the number of OUTER positions in this pipeline.
     *
     * A ParallelStepGroup, NestedPipeline, or ConditionalBranch each count
     * as a single position regardless of how many sub-steps or branches
     * they contain. For the total expanded sub-step count, use
     * flatStepCount() instead.
     *
     * @return int The number of top-level step positions (parallel groups, nested pipelines, and conditional branches count as one).
     */
    public function stepCount(): int
    {
        return count($this->steps);
    }

    /**
     * Get the total number of SUB-STEPS across the pipeline.
     *
     * Parallel groups expand to their inner sub-step count; nested
     * pipelines recurse via the inner definition's flatStepCount() so
     * nested parallel groups and nested-nested sub-pipelines expand
     * transitively. Conditional branches contribute the MAX flatStepCount
     * across their branch values (conservative upper bound used for
     * payload budgeting near the 256KB SQS NFR11 boundary — only one
     * branch runs at execution time, but the max captures the worst case).
     * Non-group entries count as one. Used for observability and test
     * assertions where the expanded job count matters (e.g., "this
     * pipeline dispatches N queued jobs across parallel batches and
     * nested sub-pipelines").
     *
     * @return int The total count of StepDefinition instances (parallel and nested groups expanded recursively; branches counted as the max across branch values).
     */
    public function flatStepCount(): int
    {
        $total = 0;

        foreach ($this->steps as $step) {
            if ($step instanceof ParallelStepGroup) {
                $total += count($step->steps);

                continue;
            }

            if ($step instanceof NestedPipeline) {
                $total += $step->definition->flatStepCount();

                continue;
            }

            if ($step instanceof ConditionalBranch) {
                $branchCounts = array_map(
                    static fn (StepDefinition|NestedPipeline $value): int => $value instanceof NestedPipeline
                        ? $value->definition->flatStepCount()
                        : 1,
                    $step->branches,
                );
                $total += $branchCounts === [] ? 0 : max($branchCounts);

                continue;
            }

            $total += 1;
        }

        return $total;
    }

    /**
     * Build a compensation mapping from the pipeline steps.
     *
     * Iterates outer positions and, for ParallelStepGroup positions,
     * recurses into their sub-steps so each sub-step with a compensation
     * class is registered on the flat mapping. For NestedPipeline positions,
     * recurses into the inner definition's compensationMapping() and merges
     * the result via array_merge(). For ConditionalBranch positions,
     * iterates ALL branch values and merges their compensation mappings
     * (rationale: the selector runs at execution time, so every branch's
     * compensation must be available in advance). Duplicate step-class
     * semantics follow outer-loop iteration order: when the same step
     * class carries different compensations in more than one declaration
     * site (outer direct entry, inner nested entry, parallel sub-step,
     * conditional branch value, or another nested-nested entry), the LAST
     * declaration processed wins. Because the outer foreach visits entries
     * in declaration order, a nested wrapper declared AFTER an outer step
     * with the same class overrides the outer mapping; a nested wrapper
     * declared BEFORE a duplicate outer step is overridden by it. This
     * mirrors the "duplicate step class silently loses compensation
     * mapping" note in deferred-work.md.
     * The resulting shape is unchanged from the non-parallel / non-nested
     * case: a class-name-keyed lookup used by the saga compensation chain
     * at reverse-order rollback time.
     *
     * @return array<string, string> Map of step class name to compensation class name; flat across parallel, nested, and branch groups.
     */
    public function compensationMapping(): array
    {
        $mapping = [];

        foreach ($this->steps as $step) {
            if ($step instanceof ParallelStepGroup) {
                foreach ($step->steps as $subStep) {
                    if ($subStep->compensationJobClass !== null) {
                        $mapping[$subStep->jobClass] = $subStep->compensationJobClass;
                    }
                }

                continue;
            }

            if ($step instanceof NestedPipeline) {
                $mapping = array_merge($mapping, $step->definition->compensationMapping());

                continue;
            }

            if ($step instanceof ConditionalBranch) {
                foreach ($step->branches as $value) {
                    if ($value instanceof NestedPipeline) {
                        $mapping = array_merge($mapping, $value->definition->compensationMapping());

                        continue;
                    }

                    if ($value->compensationJobClass !== null) {
                        $mapping[$value->jobClass] = $value->compensationJobClass;
                    }
                }

                continue;
            }

            if ($step->compensationJobClass !== null) {
                $mapping[$step->jobClass] = $step->compensationJobClass;
            }
        }

        return $mapping;
    }
}
