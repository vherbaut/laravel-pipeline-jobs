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

    /**
     * Create an exception for a ConditionalBranch built with a whitespace-only or empty-string branch key.
     *
     * Branch keys are routing identifiers emitted by the selector closure; a
     * blank or whitespace-only key would be impossible to reach deterministically
     * and is almost certainly a programming mistake.
     *
     * @return self
     */
    public static function blankBranchKey(): self
    {
        return new self(
            'Conditional branch keys must be non-empty, non-whitespace strings.',
        );
    }

    /**
     * Create an exception when a branch selector returns a non-string value.
     *
     * The selector contract requires a string branch key matching one of the
     * registered branches; any other return type surfaces as a programming
     * error at selector evaluation time and is wrapped into a
     * StepExecutionFailed by the executor for observability.
     *
     * @param string $actualType The get_debug_type() of the offending return value.
     *
     * @return self
     */
    public static function branchSelectorMustReturnString(string $actualType): self
    {
        return new self(
            'Conditional branch selector must return a string branch key, got '.$actualType.'.',
        );
    }

    /**
     * Create an exception for a ConditionalBranch embedded inside a ParallelStepGroup.
     *
     * Parallel groups deep-clone the manifest per sub-step so sub-steps can
     * run concurrently, which conflicts with the single-branch-wins
     * selector-evaluation semantic of conditional branches. Wrapping the
     * branch OUTSIDE the parallel group is the intended escape hatch.
     *
     * @return self
     */
    public static function conditionalBranchInsideParallelGroup(): self
    {
        return new self(
            'Conditional branches cannot be embedded inside parallel step groups; '
            .'the single-branch-wins selector semantic conflicts with parallel deep-cloning of the manifest. '
            .'Wrap the branch OUTSIDE the parallel group instead.',
        );
    }

    /**
     * Create an exception for a ConditionalBranch built from an empty branches array.
     *
     * Conditional branches must carry at least one branch entry; passing an
     * empty array to Step::branch() / JobPipeline::branch() is a build-time
     * programming error that surfaces before any job is enqueued.
     *
     * @return self
     */
    public static function emptyBranches(): self
    {
        return new self(
            'Conditional branch must contain at least one branch entry; got an empty branches array. '
            ."Call Step::branch(\$selector, ['key' => JobA::class, ...]) with one or more branch entries.",
        );
    }

    /**
     * Create an exception for a ConditionalBranch wrapping a ParallelStepGroup as a branch value.
     *
     * Branch values are restricted to class-strings, StepDefinition, or
     * NestedPipeline because a branch selects ONE path; a parallel group
     * would produce a single-path degenerate form. Users wrapping parallel
     * execution inside a branch must wrap the parallel group inside a
     * NestedPipeline first.
     *
     * @return self
     */
    public static function parallelInsideConditionalBranch(): self
    {
        return new self(
            'Conditional branch values cannot be ParallelStepGroup instances; '
            .'wrap parallel groups inside a NestedPipeline if you need parallel execution within a branch.',
        );
    }

    /**
     * Create an exception when a branch selector returns a key not registered in the branches array.
     *
     * The selected key is reported verbatim and the registered branch keys
     * are listed to make the mismatch obvious.
     *
     * @param string $key The selector-emitted key that did not match any registered branch.
     * @param array<int, string> $knownKeys The registered branch keys (positional list from array_keys()).
     *
     * @return self
     */
    public static function unknownBranchKey(string $key, array $knownKeys): self
    {
        $formattedKnown = $knownKeys === []
            ? '(no branches registered)'
            : implode(', ', array_map(static fn (string $knownKey): string => '"'.$knownKey.'"', $knownKeys));

        return new self(
            'Conditional branch selector returned unknown branch key "'.$key.'". '
            .'Registered branches: '.$formattedKnown.'.',
        );
    }
}
