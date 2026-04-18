<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;

/**
 * Immutable value object representing a pipeline nested as a single step within another pipeline.
 *
 * Nested pipelines let developers compose complex workflows from reusable
 * pipeline building blocks (FR4). The inner PipelineDefinition executes
 * sequentially within its outer position: all inner steps share the outer
 * PipelineContext, and their completed class names append flat onto the
 * outer manifest's $completedSteps list so saga compensation operates over
 * one merged reverse-order chain.
 *
 * Construction is factory-only: either from an already-built
 * PipelineDefinition via fromDefinition(), or from a mutable PipelineBuilder
 * via fromBuilder() (which eagerly calls $builder->build() at wrap time,
 * matching the "eager snapshot" semantic used by
 * PipelineBuilder::toListener()). The constructor is private to force
 * callers through the factories and fail fast on empty inner builders.
 *
 * ## FailStrategy inheritance
 *
 * The OUTER pipeline's FailStrategy governs all inner steps. The inner
 * PipelineDefinition's own $failStrategy field is structurally present but
 * IGNORED once the definition is wrapped for nesting. Rationale: a nested
 * pipeline is one step from the outer pipeline's perspective; mixing
 * strategies across levels would yield confusing saga semantics where one
 * inner-step failure might skip while its outer chain compensates.
 *
 * ## Payload footprint (NFR11)
 *
 * Unlike ParallelStepGroup, a nested pipeline does NOT multiply the queued
 * payload by N. The inner definition contributes ONE recursive serialized
 * description of its sub-steps (steps, configs, conditions); every wrapper
 * hop carries the same manifest. Additional payload cost scales linearly
 * with the inner definition's flat step count. No automatic limit-check is
 * added: users operating near the 256KB SQS limit must budget for the
 * recursive description's size just as they would for a top-level pipeline.
 *
 * ## Nesting boundaries
 *
 * - Nested-inside-nested IS permitted (multi-level composition).
 * - Parallel-inside-nested IS permitted (the parallel sub-group fans out
 *   at its own outer position within the inner definition's steps array).
 * - Nested-inside-parallel is EXPLICITLY REJECTED at build time because
 *   parallel deep-clones the manifest per sub-step, breaking the
 *   shared-completedSteps semantic nested compensation relies on. The
 *   rejection lives in ParallelStepGroup::fromArray() via
 *   InvalidPipelineDefinition::nestedPipelineInsideParallelGroup().
 */
final class NestedPipeline
{
    /**
     * Build the immutable nested-pipeline wrapper.
     *
     * @param PipelineDefinition $definition The pre-built immutable inner pipeline definition captured at wrap time.
     * @param string|null $name Optional user-visible sub-pipeline name surfaced through observability (hooks, assertion helpers).
     */
    private function __construct(
        public readonly PipelineDefinition $definition,
        public readonly ?string $name = null,
    ) {}

    /**
     * Wrap a pre-built PipelineDefinition as a nested sub-pipeline.
     *
     * The definition is captured as-is; no additional validation fires here
     * beyond what PipelineDefinition's own constructor enforced at build
     * time. The inner definition's $failStrategy field is retained but
     * ignored at execution time (see class-level "FailStrategy inheritance").
     *
     * @param PipelineDefinition $definition The pre-built inner pipeline definition to wrap.
     * @param string|null $name Optional user-visible sub-pipeline name for observability; defaults to null.
     *
     * @return self A new NestedPipeline wrapping the given definition.
     */
    public static function fromDefinition(PipelineDefinition $definition, ?string $name = null): self
    {
        return new self($definition, $name);
    }

    /**
     * Wrap a PipelineBuilder by eagerly snapshotting its PipelineDefinition.
     *
     * Calls $builder->build() at wrap time so empty-steps validation fires
     * immediately (instead of deferred at execution time). Matches the
     * eager-snapshot semantic used by PipelineBuilder::toListener().
     * Subsequent mutations to the builder have no effect on the returned
     * NestedPipeline.
     *
     * @param PipelineBuilder $builder The mutable builder to snapshot; build() is invoked eagerly.
     * @param string|null $name Optional user-visible sub-pipeline name for observability; defaults to null.
     *
     * @return self A new NestedPipeline wrapping the snapshotted definition.
     *
     * @throws InvalidPipelineDefinition Propagated from PipelineBuilder::build() when the builder has no steps.
     */
    public static function fromBuilder(PipelineBuilder $builder, ?string $name = null): self
    {
        return new self($builder->build(), $name);
    }
}
