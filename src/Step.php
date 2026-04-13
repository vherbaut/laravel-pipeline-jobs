<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

/**
 * Public factory for building conditional and unconditional pipeline steps.
 *
 * Exposes the `when`, `unless`, and `make` static constructors used by the
 * array-based Pipeline API. Each factory returns an immutable
 * StepDefinition that the builder and executors consume. This class holds
 * no mutable state; it is the user-facing boundary while StepDefinition
 * remains the internal value object.
 */
final class Step
{
    /**
     * Build a conditional step that runs ONLY when the condition evaluates to true.
     *
     * The condition closure is evaluated at runtime against the current
     * PipelineContext immediately before the step would execute, so earlier
     * steps' context mutations are visible to the predicate.
     *
     * @param Closure(PipelineContext): bool $condition Predicate evaluated against the live PipelineContext.
     * @param string $jobClass Fully qualified job class name to execute when the condition holds.
     *
     * @return StepDefinition
     */
    public static function when(Closure $condition, string $jobClass): StepDefinition
    {
        return new StepDefinition(
            jobClass: $jobClass,
            condition: $condition,
            conditionNegated: false,
        );
    }

    /**
     * Build a conditional step that runs UNLESS the condition evaluates to true.
     *
     * Same runtime-evaluation semantics as `when()` with the boolean result
     * inverted: the step executes when the closure returns a falsy value.
     *
     * @param Closure(PipelineContext): bool $condition Predicate evaluated against the live PipelineContext.
     * @param string $jobClass Fully qualified job class name to execute when the condition is falsy.
     *
     * @return StepDefinition
     */
    public static function unless(Closure $condition, string $jobClass): StepDefinition
    {
        return new StepDefinition(
            jobClass: $jobClass,
            condition: $condition,
            conditionNegated: true,
        );
    }

    /**
     * Build an unconditional step from a job class name.
     *
     * Convenience alias that mirrors the factory `make()` convention used
     * across the package; equivalent to `StepDefinition::fromJobClass()`.
     *
     * @param string $jobClass Fully qualified job class name to execute.
     *
     * @return StepDefinition
     */
    public static function make(string $jobClass): StepDefinition
    {
        return StepDefinition::fromJobClass($jobClass);
    }
}
