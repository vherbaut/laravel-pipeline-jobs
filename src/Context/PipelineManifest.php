<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Context;

use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Mutable DTO carrying the execution state of a pipeline run.
 *
 * Travels with each job in the queue payload. Identity fields are
 * readonly (set once at creation), while execution state fields are
 * mutable and updated by the executor as the pipeline progresses.
 * All properties are serializable (scalars, arrays of strings, or
 * PipelineContext with SerializesModels support).
 */
final class PipelineManifest
{
    /**
     * Create a new pipeline manifest.
     *
     * @param string $pipelineId Unique identifier for this pipeline run (UUID).
     * @param string|null $pipelineName Optional human-readable name for this pipeline.
     * @param array<int, string> $stepClasses Ordered list of job class names.
     * @param array<string, string> $compensationMapping Map of step class name to compensation class name.
     * @param array<int, array{closure: SerializableClosure, negated: bool}> $stepConditions Per-step condition entries keyed by step index.
     * @param int $currentStepIndex Index of the current step being executed.
     * @param array<int, string> $completedSteps List of completed step class names.
     * @param PipelineContext|null $context The user's pipeline context DTO.
     */
    public function __construct(
        public readonly string $pipelineId,
        public readonly ?string $pipelineName,
        /** @var array<int, string> */
        public readonly array $stepClasses,
        /** @var array<string, string> */
        public readonly array $compensationMapping,
        /** @var array<int, array{closure: SerializableClosure, negated: bool}> */
        public readonly array $stepConditions,
        public int $currentStepIndex,
        /** @var array<int, string> */
        public array $completedSteps,
        public ?PipelineContext $context,
    ) {}

    /**
     * Create a new pipeline manifest with auto-generated UUID and default execution state.
     *
     * @param array<int, string> $stepClasses Ordered list of job class names.
     * @param PipelineContext|null $context The user's pipeline context DTO.
     * @param array<string, string> $compensationMapping Map of step class name to compensation class name.
     * @param string|null $pipelineName Optional human-readable name for this pipeline.
     * @param array<int, array{closure: SerializableClosure, negated: bool}> $stepConditions Per-step condition entries keyed by step index.
     *
     * @return self
     */
    public static function create(
        array $stepClasses,
        ?PipelineContext $context = null,
        array $compensationMapping = [],
        ?string $pipelineName = null,
        array $stepConditions = [],
    ): self {
        return new self(
            pipelineId: (string) Str::uuid(),
            pipelineName: $pipelineName,
            stepClasses: $stepClasses,
            compensationMapping: $compensationMapping,
            stepConditions: $stepConditions,
            currentStepIndex: 0,
            completedSteps: [],
            context: $context,
        );
    }

    /**
     * Advance to the next step in the pipeline.
     *
     * @return void
     */
    public function advanceStep(): void
    {
        $this->currentStepIndex++;
    }

    /**
     * Record a step as completed.
     *
     * @param string $stepClass Fully qualified class name of the completed step.
     * @return void
     */
    public function markStepCompleted(string $stepClass): void
    {
        $this->completedSteps[] = $stepClass;
    }

    /**
     * Set the pipeline context.
     *
     * @param PipelineContext $context The user's pipeline context DTO.
     * @return void
     */
    public function setContext(PipelineContext $context): void
    {
        $this->context = $context;
    }
}
