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

    /**
     * Build a conditional branch group that routes to ONE of several step values at runtime.
     *
     * The selector closure is evaluated once against the live PipelineContext
     * immediately before the branch position would execute; its return value
     * looks up the matching branch in the $branches map and the selected
     * value executes in place of the branch position. After the selected
     * branch completes, execution converges back to the next outer step
     * (FR26, FR27). Branch values may be class-strings, pre-built
     * StepDefinition instances, NestedPipeline instances, PipelineBuilder
     * instances (auto-wrapped), or PipelineDefinition instances (auto-wrapped);
     * ParallelStepGroup values are rejected at factory time.
     *
     * The selector is guaranteed to run EXACTLY ONCE per branch traversal
     * (in sync mode inline, and in queued mode on the branch wrapper before
     * the next wrapper dispatches): downstream wrappers see the resolved
     * value substituted into the manifest and never re-run the selector.
     * This is load-bearing for selectors with side effects.
     *
     * In queued mode the selector closure is wrapped via SerializableClosure
     * so it survives the queue boundary. Selectors capturing $this from a
     * non-serializable enclosing class (or referencing non-serializable
     * values via `use(...)`) will fail at enqueue time; prefer static
     * selectors or capture only serializable values.
     *
     * WARNING: when two branches declare the SAME job class with DIFFERENT
     * compensation classes, the saga `compensationMapping` resolves via
     * `array_merge` later-wins: the LAST-declared branch's compensation is
     * registered under that class name. If the FIRST-declared branch runs
     * and fails under StopAndCompensate, the compensation for the LAST
     * branch is invoked instead. Prefer unique job classes per branch, or
     * accept the documented semantic.
     *
     * @param Closure(PipelineContext): string $selector Branch selector closure invoked against the live PipelineContext; returns the branch key string.
     * @param array<array-key, mixed> $branches Map of branch keys to step values (class-string, StepDefinition, NestedPipeline, PipelineBuilder, or PipelineDefinition).
     * @param string|null $name Optional user-visible branch name for observability; defaults to null.
     *
     * @return ConditionalBranch A new ConditionalBranch with normalized branch values.
     *
     * @throws Exceptions\InvalidPipelineDefinition When $branches is empty, carries a blank key, contains a ParallelStepGroup value, or contains an unsupported value type.
     */
    public static function branch(Closure $selector, array $branches, ?string $name = null): ConditionalBranch
    {
        return ConditionalBranch::fromArray($selector, $branches, $name);
    }
}
