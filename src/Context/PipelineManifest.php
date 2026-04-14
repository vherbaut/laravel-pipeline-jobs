<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Context;

use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;

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
     * The throwable raised by the failed step; null while the pipeline has not failed.
     *
     * Never serialized into queue payloads. Used in-process only by the executor
     * that catches the failure, then cleared to null before dispatching any
     * downstream jobs so the queue payload stays serializable.
     *
     * @var Throwable|null
     */
    public ?Throwable $failureException = null;

    /**
     * The fully qualified class name of the step that failed; null while the pipeline has not failed.
     *
     * @var string|null
     */
    public ?string $failedStepClass = null;

    /**
     * The zero-based index of the step that failed; null while the pipeline has not failed.
     *
     * @var int|null
     */
    public ?int $failedStepIndex = null;

    /**
     * Closures (wrapped in SerializableClosure) invoked before each non-skipped step executes.
     *
     * Populated by PipelineBuilder::run() / PipelineBuilder::toListener() after
     * the manifest is created; defaults to an empty array when the pipeline
     * registers no beforeEach hooks. Executors unwrap each entry via
     * `$entry->getClosure()` at invocation time. Mirrors the stepConditions
     * pattern but scoped to pipeline-level observability rather than
     * per-step conditional execution.
     *
     * @var array<int, SerializableClosure>
     */
    public array $beforeEachHooks = [];

    /**
     * Closures (wrapped in SerializableClosure) invoked after each successful step.
     *
     * Populated by PipelineBuilder::run() / PipelineBuilder::toListener() after
     * the manifest is created; defaults to an empty array when the pipeline
     * registers no afterEach hooks. Fires AFTER the step's handle() returns
     * and BEFORE markStepCompleted()/advanceStep().
     *
     * @var array<int, SerializableClosure>
     */
    public array $afterEachHooks = [];

    /**
     * Closures (wrapped in SerializableClosure) invoked when a step (or another hook) throws.
     *
     * Populated by PipelineBuilder::run() / PipelineBuilder::toListener() after
     * the manifest is created; defaults to an empty array when the pipeline
     * registers no onStepFailed hooks. Fires inside the executor's catch
     * block, AFTER the failure fields are recorded on the manifest, but
     * BEFORE the FailStrategy branching.
     *
     * @var array<int, SerializableClosure>
     */
    public array $onStepFailedHooks = [];

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
     * @param FailStrategy $failStrategy Saga failure strategy propagated from the PipelineDefinition so queued executors can decide whether to trigger compensation after a step failure.
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
        public FailStrategy $failStrategy = FailStrategy::StopImmediately,
    ) {}

    /**
     * Create a new pipeline manifest with auto-generated UUID and default execution state.
     *
     * @param array<int, string> $stepClasses Ordered list of job class names.
     * @param PipelineContext|null $context The user's pipeline context DTO.
     * @param array<string, string> $compensationMapping Map of step class name to compensation class name.
     * @param string|null $pipelineName Optional human-readable name for this pipeline.
     * @param array<int, array{closure: SerializableClosure, negated: bool}> $stepConditions Per-step condition entries keyed by step index.
     * @param FailStrategy $failStrategy Saga failure strategy propagated from the PipelineDefinition so executors can decide whether to trigger compensation after a step failure.
     *
     * @return self
     */
    public static function create(
        array $stepClasses,
        ?PipelineContext $context = null,
        array $compensationMapping = [],
        ?string $pipelineName = null,
        array $stepConditions = [],
        FailStrategy $failStrategy = FailStrategy::StopImmediately,
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
            failStrategy: $failStrategy,
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

    /**
     * Produce the serialized payload for queue transport.
     *
     * Excludes $failureException by design: Throwable traces hold resources
     * and closures that are not reliably serializable (NFR19). The invariant
     * "failureException is in-process only" is enforced here structurally, so
     * queue payloads cannot leak the exception even if a caller forgets to
     * null the field before dispatch.
     *
     * Includes the three hook arrays (beforeEachHooks, afterEachHooks,
     * onStepFailedHooks). SerializableClosure is the purpose-built
     * serialization mechanism for closures and survives queue transport
     * unchanged (unlike raw Throwable).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'pipelineId' => $this->pipelineId,
            'pipelineName' => $this->pipelineName,
            'stepClasses' => $this->stepClasses,
            'compensationMapping' => $this->compensationMapping,
            'stepConditions' => $this->stepConditions,
            'currentStepIndex' => $this->currentStepIndex,
            'completedSteps' => $this->completedSteps,
            'context' => $this->context,
            'failStrategy' => $this->failStrategy,
            'failedStepClass' => $this->failedStepClass,
            'failedStepIndex' => $this->failedStepIndex,
            'beforeEachHooks' => $this->beforeEachHooks,
            'afterEachHooks' => $this->afterEachHooks,
            'onStepFailedHooks' => $this->onStepFailedHooks,
        ];
    }

    /**
     * Restore the manifest state from a deserialized payload.
     *
     * Always restores $failureException as null since the property is never
     * carried across the serialization boundary (see __serialize). Hook
     * arrays default to empty when the payload predates Story 6.1 (legacy
     * queue payloads in flight during rolling deployment), matching the
     * defensive ?? pattern used for failedStepClass / failedStepIndex.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $this->pipelineId = $data['pipelineId'];
        $this->pipelineName = $data['pipelineName'];
        $this->stepClasses = $data['stepClasses'];
        $this->compensationMapping = $data['compensationMapping'];
        $this->stepConditions = $data['stepConditions'];
        $this->currentStepIndex = $data['currentStepIndex'];
        $this->completedSteps = $data['completedSteps'];
        $this->context = $data['context'];
        $this->failStrategy = $data['failStrategy'];
        $this->failedStepClass = $data['failedStepClass'] ?? null;
        $this->failedStepIndex = $data['failedStepIndex'] ?? null;
        $this->failureException = null;
        $this->beforeEachHooks = $data['beforeEachHooks'] ?? [];
        $this->afterEachHooks = $data['afterEachHooks'] ?? [];
        $this->onStepFailedHooks = $data['onStepFailedHooks'] ?? [];
    }
}
