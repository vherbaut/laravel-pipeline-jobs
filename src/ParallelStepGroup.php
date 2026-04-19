<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;

/**
 * Immutable value object representing a group of pipeline steps that execute in parallel.
 *
 * Parallel groups fan-out their contained sub-steps to concurrent workers via
 * Bus::batch() when the enclosing pipeline is queued. Under synchronous
 * execution, sub-steps run sequentially in declaration order within the
 * current PHP process (semantic parallelism: all sub-steps run, their context
 * mutations are observable after the group completes, and the group advances
 * the pipeline's outer position as a single logical step).
 *
 * Nesting is explicitly out of scope: a ParallelStepGroup
 * may only contain StepDefinition instances, not other ParallelStepGroup
 * instances. Parallel groups also EXPLICITLY reject NestedPipeline
 * entries: nesting across parallel
 * boundaries breaks the shared-completedSteps compensation semantic because
 * ParallelStepGroup deep-clones the manifest per sub-step while nested-
 * pipeline compensation requires one merged flat list. Wrap the nested
 * pipeline OUTSIDE the parallel group instead.
 *
 * Conditions on parallel groups are also rejected at build time: individual
 * sub-steps may carry their own Step::when() / Step::unless() closures, but
 * no aggregate when()/unless() is available on the group itself.
 * ConditionalBranch is accepted as a fourth slot type, but branches inside a
 * parallel group are EXPLICITLY REJECTED via
 * InvalidPipelineDefinition::conditionalBranchInsideParallelGroup(): parallel
 * deep-clones the manifest per sub-step, multiplying the selector evaluation
 * across workers and breaking the single-branch-wins semantic.
 *
 * Payload footprint (NFR11): a parallel group with N sub-steps multiplies
 * the queued payload footprint by N during the batch window because every
 * ParallelStepJob wrapper carries its own deep-cloned PipelineContext plus
 * the full PipelineManifest. Users operating near the 256KB SQS limit must
 * factor this multiplier into their context size budget.
 *
 * Construction is factory-only via ParallelStepGroup::fromArray(). The
 * constructor is private to force validation through the factory.
 */
final class ParallelStepGroup
{
    /**
     * Build the immutable parallel group instance.
     *
     * @param array<int, StepDefinition> $steps Pre-validated, pre-normalized list of sub-step definitions in declaration order.
     */
    private function __construct(
        /** @var array<int, StepDefinition> */
        public readonly array $steps,
    ) {}

    /**
     * Factory producing a parallel step group from an array of class-strings or StepDefinition instances.
     *
     * Each entry is normalized into a StepDefinition: class-strings are
     * wrapped via StepDefinition::fromJobClass(), and pre-built
     * StepDefinition instances are appended as-is (preserving any per-step
     * retry/backoff/timeout/queue/connection/condition configuration the
     * caller attached upstream).
     *
     * The factory enforces two invariants:
     *  - the input array is non-empty (empty arrays throw
     *    InvalidPipelineDefinition::emptyParallelGroup()),
     *  - every entry is a string or a StepDefinition (any other type throws
     *    InvalidPipelineDefinition with the offending type named).
     *
     * Nested ParallelStepGroup entries are NOT allowed (see
     * class-level PHPDoc). Such entries fall through to the generic
     * "unsupported type" branch and throw InvalidPipelineDefinition.
     *
     * The declared item type intentionally widens to mixed because callers
     * may pass untrusted data (e.g., from configuration) and the runtime
     * check exists precisely to catch that case.
     *
     * @param array<int, mixed> $jobs Sub-step class-strings or pre-built StepDefinition instances; other types are rejected at construction time.
     *
     * @return self A new ParallelStepGroup containing the normalized sub-step list in declaration order.
     *
     * @throws InvalidPipelineDefinition When $jobs is empty or contains an item that is neither a class-string nor a StepDefinition.
     */
    public static function fromArray(array $jobs): self
    {
        if ($jobs === []) {
            throw InvalidPipelineDefinition::emptyParallelGroup();
        }

        $steps = [];

        foreach ($jobs as $job) {
            if (is_string($job)) {
                $steps[] = StepDefinition::fromJobClass($job);

                continue;
            }

            if ($job instanceof StepDefinition) {
                $steps[] = $job;

                continue;
            }

            if ($job instanceof self) {
                throw InvalidPipelineDefinition::nestedParallelGroup();
            }

            if ($job instanceof NestedPipeline) {
                throw InvalidPipelineDefinition::nestedPipelineInsideParallelGroup();
            }

            if ($job instanceof ConditionalBranch) {
                throw InvalidPipelineDefinition::conditionalBranchInsideParallelGroup();
            }

            throw new InvalidPipelineDefinition(
                'Parallel step group items must be class-string or StepDefinition instances, got '.get_debug_type($job).'.',
            );
        }

        return new self($steps);
    }
}
