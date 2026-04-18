<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Exceptions;

/**
 * Thrown when a pipeline definition is invalid or incomplete.
 */
class InvalidPipelineDefinition extends PipelineException
{
    /**
     * Create an exception for a pipeline defined with no steps.
     *
     * @return self
     */
    public static function emptySteps(): self
    {
        return new self('A pipeline must contain at least one step.');
    }

    /**
     * Create an exception for a parallel step group built from an empty array.
     *
     * Parallel groups must carry at least one sub-step; passing an empty
     * array to JobPipeline::parallel() / PipelineBuilder::parallel() is a
     * build-time programming error that surfaces before any job is enqueued.
     *
     * @return self
     */
    public static function emptyParallelGroup(): self
    {
        return new self(
            'Parallel step group must contain at least one sub-step; got an empty array. Call JobPipeline::parallel([JobA::class, JobB::class, ...]) with one or more sub-steps.',
        );
    }

    /**
     * Create an exception for a parallel step group containing a nested parallel group.
     *
     * Nesting is explicitly out of scope for Epic 8 Story 8.1 (sub-pipeline
     * nesting lives in Story 8.2). The targeted message names the invariant
     * and points users at the supported entry types so the build-time error
     * is actionable.
     *
     * @return self
     */
    public static function nestedParallelGroup(): self
    {
        return new self(
            'Nested parallel step groups are not supported (Epic 8 Story 8.2 covers sub-pipeline nesting). '
            .'A ParallelStepGroup may only contain class-strings or StepDefinition instances.',
        );
    }

    /**
     * Create an exception for a parallel step group containing a nested pipeline.
     *
     * Story 8.2 adds NestedPipeline as a third slot type within a pipeline's
     * outer $steps array, but embedding a nested pipeline inside a parallel
     * group is explicitly rejected. Rationale: ParallelStepGroup deep-clones
     * the manifest per sub-step so sub-steps can run concurrently, which
     * conflicts with the shared-completedSteps semantic that nested-pipeline
     * saga compensation relies on. Wrapping the pipeline OUTSIDE the parallel
     * group (or flattening its inner steps into the group) is the intended
     * escape hatch.
     *
     * @return self
     */
    public static function nestedPipelineInsideParallelGroup(): self
    {
        return new self(
            'Nested pipelines cannot be embedded inside parallel step groups; '
            .'nesting across parallel boundaries breaks the shared-completedSteps compensation semantic. '
            .'Wrap the pipeline OUTSIDE the parallel group instead.',
        );
    }
}
