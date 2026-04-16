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
}
