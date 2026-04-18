<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;

/**
 * Immutable value object representing a conditional branch group within a pipeline.
 *
 * A ConditionalBranch carries a user-supplied selector closure and a map of
 * branch keys to step values (class-strings, StepDefinition instances, or
 * NestedPipeline instances). At execution time the selector is invoked once
 * against the live PipelineContext; its return value looks up the matching
 * branch and that single path executes in place of the branch position. The
 * outer pipeline's position advances exactly once after the selected branch
 * completes (FR26, FR27 — branches converge to the next outer step).
 *
 * ConditionalBranch sits alongside ParallelStepGroup and NestedPipeline in
 * the builder's $steps array as a fourth non-StepDefinition slot type with a
 * symmetric manifest shape carrying the discriminator tag 'branch'.
 *
 * ## Nesting boundaries
 *
 * - Branch-inside-Nested IS permitted (nested pipelines share the outer
 *   manifest, so branches compose naturally at any inner position).
 * - Nested-as-branch-value IS permitted (PipelineBuilder / PipelineDefinition
 *   branch values are auto-wrapped into NestedPipeline at factory time).
 * - Branch-inside-Parallel is EXPLICITLY REJECTED at build time because
 *   parallel deep-clones the manifest per sub-step, multiplying the selector
 *   evaluation across workers and breaking the single-branch-wins semantic.
 *   The rejection lives in ParallelStepGroup::fromArray() via
 *   InvalidPipelineDefinition::conditionalBranchInsideParallelGroup().
 * - ParallelStepGroup as a branch value is EXPLICITLY REJECTED at factory
 *   time via InvalidPipelineDefinition::parallelInsideConditionalBranch();
 *   users needing parallel execution inside a branch must wrap the parallel
 *   group inside a NestedPipeline first.
 *
 * ## FailStrategy inheritance
 *
 * The OUTER pipeline's FailStrategy governs all selected-branch inner steps.
 * When a branch value is a NestedPipeline, that inner pipeline's own
 * $failStrategy field is structurally present but IGNORED at execution time
 * (same rule as NestedPipeline::fromDefinition() / fromBuilder()).
 *
 * ## Payload footprint (NFR11)
 *
 * Unlike ParallelStepGroup, a branch does NOT multiply the queued payload
 * at dispatch time. The manifest carries the FULL description of ALL
 * branches (selector closure wrapped via SerializableClosure plus every
 * branch value's sub-description) in ONE payload, but only ONE branch runs.
 * Additional payload cost scales with
 * sizeOf(selector) + sum_k(sizeOf(branchValue_k) + sizeOf(branchConfig_k) + sizeOf(branchCondition_k)).
 * No automatic limit-check is added; users operating near the 256KB SQS
 * limit must budget for the recursive description's size.
 *
 * ## Selector side effects
 *
 * The selector closure is evaluated EXACTLY ONCE per branch traversal in
 * queued mode via the rebrand-then-dispatch pattern: the branch wrapper's
 * process resolves the selected value and substitutes it into the manifest
 * BEFORE dispatching the next wrapper. Downstream wrappers see a plain
 * non-branch shape at the outer position and never re-run the selector.
 * This is load-bearing for selectors with side effects (logging, cache
 * lookups): the same guarantee applies in sync mode where selectors run
 * inline exactly once.
 *
 * Construction is factory-only via ConditionalBranch::fromArray(). The
 * constructor is private to force validation through the factory.
 */
final class ConditionalBranch
{
    /**
     * Build the immutable conditional-branch wrapper.
     *
     * @param Closure $selector User selector closure invoked against PipelineContext; returns the branch key string.
     * @param array<string, StepDefinition|NestedPipeline> $branches Pre-normalized map of branch keys to step values.
     * @param string|null $name Optional user-visible branch name surfaced through observability (assertion helpers, logs).
     */
    private function __construct(
        public readonly Closure $selector,
        /** @var array<string, StepDefinition|NestedPipeline> */
        public readonly array $branches,
        public readonly ?string $name = null,
    ) {}

    /**
     * Factory producing a ConditionalBranch from a selector closure and branches map.
     *
     * Each branch value is normalized into a StepDefinition or NestedPipeline:
     *  - class-string → StepDefinition::fromJobClass()
     *  - pre-built StepDefinition → appended as-is (preserves per-step config)
     *  - PipelineBuilder → NestedPipeline::fromBuilder() (eager snapshot)
     *  - PipelineDefinition → NestedPipeline::fromDefinition()
     *  - pre-built NestedPipeline → appended as-is
     *  - ParallelStepGroup → rejected via parallelInsideConditionalBranch()
     *  - any other type → rejected with a targeted error message
     *
     * The factory also enforces three structural invariants:
     *  - the $branches array is non-empty (emptyBranches() on failure),
     *  - every branch key is a non-empty, non-whitespace string
     *    (blankBranchKey() on failure),
     *  - no branch value is a ParallelStepGroup
     *    (parallelInsideConditionalBranch() on failure).
     *
     * @param Closure $selector Selector closure typed Closure(PipelineContext): string.
     * @param array<array-key, mixed> $branches Map of branch key to branch value (class-string, StepDefinition, NestedPipeline, PipelineBuilder, or PipelineDefinition); keys must be non-empty, non-whitespace strings.
     * @param string|null $name Optional user-visible branch name for observability; defaults to null.
     *
     * @return self A new ConditionalBranch with normalized branches.
     *
     * @throws InvalidPipelineDefinition When $branches is empty, contains a blank key, contains a ParallelStepGroup value, or contains an unsupported value type.
     */
    public static function fromArray(Closure $selector, array $branches, ?string $name = null): self
    {
        if ($branches === []) {
            throw InvalidPipelineDefinition::emptyBranches();
        }

        $normalized = [];

        foreach ($branches as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                throw InvalidPipelineDefinition::blankBranchKey();
            }

            if (is_string($value)) {
                $normalized[$key] = StepDefinition::fromJobClass($value);

                continue;
            }

            if ($value instanceof StepDefinition) {
                $normalized[$key] = $value;

                continue;
            }

            if ($value instanceof NestedPipeline) {
                $normalized[$key] = $value;

                continue;
            }

            if ($value instanceof PipelineBuilder) {
                $normalized[$key] = NestedPipeline::fromBuilder($value);

                continue;
            }

            if ($value instanceof PipelineDefinition) {
                $normalized[$key] = NestedPipeline::fromDefinition($value);

                continue;
            }

            if ($value instanceof ParallelStepGroup) {
                throw InvalidPipelineDefinition::parallelInsideConditionalBranch();
            }

            throw new InvalidPipelineDefinition(
                'Conditional branch values must be class-string, StepDefinition, NestedPipeline, PipelineBuilder, or PipelineDefinition, got '
                .get_debug_type($value).' for key "'.$key.'".',
            );
        }

        return new self($selector, $normalized, $name);
    }
}
