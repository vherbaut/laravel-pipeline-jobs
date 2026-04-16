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
     * Pipeline-level onSuccess callback (wrapped in SerializableClosure for queue transport).
     *
     * Populated by PipelineBuilder::run() / PipelineBuilder::toListener() /
     * FakePipelineBuilder::executeWithRecording() after the manifest is
     * created; defaults to null when the pipeline registers no onSuccess
     * callback. Executors unwrap via $this->onSuccessCallback?->getClosure()
     * at firing time. Fires once on the success tail BEFORE onComplete.
     *
     * Semantic: "the pipeline reached the success tail", NOT "every step
     * succeeded". Under FailStrategy::SkipAndContinue intermediate step
     * failures are converted into continuations, so this callback still
     * fires when the pipeline completes with one or more skip-recovered
     * steps. Users needing per-step failure observability should register
     * onStepFailed per-step hooks (Story 6.1).
     *
     * @var SerializableClosure|null
     */
    public ?SerializableClosure $onSuccessCallback = null;

    /**
     * Pipeline-level onFailure callback (wrapped in SerializableClosure for queue transport).
     *
     * Populated by PipelineBuilder::run() / PipelineBuilder::toListener() /
     * FakePipelineBuilder::executeWithRecording() after the manifest is
     * created; defaults to null when the pipeline registers no onFailure
     * Closure callback (distinct from the FailStrategy enum carried on
     * $failStrategy). Fires inside the catch block AFTER per-step
     * onStepFailed hooks (Story 6.1) AND AFTER compensation AND BEFORE
     * the terminal rethrow. Does NOT fire under FailStrategy::SkipAndContinue.
     *
     * Per-mode ordering nuance for StopAndCompensate:
     * - Sync and recording: the compensation chain has COMPLETED before the
     *   callback fires (synchronous invocation).
     * - Queued: the compensation chain has been DISPATCHED as a Bus::chain
     *   before the callback fires; individual CompensationStepJob instances
     *   execute on their own workers LATER. The callback observes a
     *   post-DISPATCH state, not a post-EXECUTION state.
     *
     * @var SerializableClosure|null
     */
    public ?SerializableClosure $onFailureCallback = null;

    /**
     * Pipeline-level onComplete callback (wrapped in SerializableClosure for queue transport).
     *
     * Populated by PipelineBuilder::run() / PipelineBuilder::toListener() /
     * FakePipelineBuilder::executeWithRecording() after the manifest is
     * created; defaults to null when the pipeline registers no onComplete
     * callback. Fires AFTER onSuccess on the success path and AFTER
     * onFailure on the failure path, always as the final callback on
     * either terminal branch.
     *
     * @var SerializableClosure|null
     */
    public ?SerializableClosure $onCompleteCallback = null;

    /**
     * Create a new pipeline manifest.
     *
     * @param string $pipelineId Unique identifier for this pipeline run (UUID).
     * @param string|null $pipelineName Optional human-readable name for this pipeline.
     * @param array<int, string|array{type: string, classes: array<int, string>}> $stepClasses Ordered list of job class names. Parallel-group positions carry a nested `['type' => 'parallel', 'classes' => array<int, string>]` shape in lieu of a flat class-string.
     * @param array<string, string> $compensationMapping Map of step class name to compensation class name.
     * @param array<int, array{closure: SerializableClosure, negated: bool}|array{type: string, entries: array<int, array{closure: SerializableClosure, negated: bool}|null>}> $stepConditions Per-step condition entries keyed by step index. Parallel groups carry a nested `['type' => 'parallel', 'entries' => [...]]` shape where each inner entry is the flat shape or null for an unconditional sub-step.
     * @param int $currentStepIndex Index of the current step being executed.
     * @param array<int, string> $completedSteps List of completed step class names.
     * @param PipelineContext|null $context The user's pipeline context DTO.
     * @param FailStrategy $failStrategy Saga failure strategy propagated from the PipelineDefinition so queued executors can decide whether to trigger compensation after a step failure.
     * @param array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}|array{type: string, configs: array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}>}> $stepConfigs Per-step resolved queue / connection / sync / retry count / backoff delay (seconds) / wrapper timeout (seconds) configuration indexed by step position. Parallel groups carry a nested `['type' => 'parallel', 'configs' => [...]]` shape where each inner entry is the flat config shape per sub-step.
     */
    public function __construct(
        public readonly string $pipelineId,
        public readonly ?string $pipelineName,
        /** @var array<int, string|array{type: string, classes: array<int, string>}> */
        public readonly array $stepClasses,
        /** @var array<string, string> */
        public readonly array $compensationMapping,
        /** @var array<int, array{closure: SerializableClosure, negated: bool}|array{type: string, entries: array<int, array{closure: SerializableClosure, negated: bool}|null>}> */
        public readonly array $stepConditions,
        public int $currentStepIndex,
        /** @var array<int, string> */
        public array $completedSteps,
        public ?PipelineContext $context,
        public FailStrategy $failStrategy = FailStrategy::StopImmediately,
        /** @var array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}|array{type: string, configs: array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}>}> */
        public readonly array $stepConfigs = [],
    ) {}

    /**
     * Create a new pipeline manifest with auto-generated UUID and default execution state.
     *
     * @param array<int, string|array{type: string, classes: array<int, string>}> $stepClasses Ordered list of job class names; parallel positions carry a nested shape.
     * @param PipelineContext|null $context The user's pipeline context DTO.
     * @param array<string, string> $compensationMapping Map of step class name to compensation class name.
     * @param string|null $pipelineName Optional human-readable name for this pipeline.
     * @param array<int, array{closure: SerializableClosure, negated: bool}|array{type: string, entries: array<int, array{closure: SerializableClosure, negated: bool}|null>}> $stepConditions Per-step condition entries keyed by step index; parallel groups carry a nested shape.
     * @param FailStrategy $failStrategy Saga failure strategy propagated from the PipelineDefinition so executors can decide whether to trigger compensation after a step failure.
     * @param array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}|array{type: string, configs: array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}>}> $stepConfigs Per-step resolved queue / connection / sync / retry / backoff / timeout configuration indexed by step position; parallel positions carry a nested shape.
     *
     * @return self
     */
    public static function create(
        /** @var array<int, string|array{type: string, classes: array<int, string>}> */
        array $stepClasses,
        ?PipelineContext $context = null,
        array $compensationMapping = [],
        ?string $pipelineName = null,
        array $stepConditions = [],
        FailStrategy $failStrategy = FailStrategy::StopImmediately,
        array $stepConfigs = [],
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
            stepConfigs: $stepConfigs,
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
            'stepConfigs' => $this->stepConfigs,
            'currentStepIndex' => $this->currentStepIndex,
            'completedSteps' => $this->completedSteps,
            'context' => $this->context,
            'failStrategy' => $this->failStrategy,
            'failedStepClass' => $this->failedStepClass,
            'failedStepIndex' => $this->failedStepIndex,
            'beforeEachHooks' => $this->beforeEachHooks,
            'afterEachHooks' => $this->afterEachHooks,
            'onStepFailedHooks' => $this->onStepFailedHooks,
            'onSuccessCallback' => $this->onSuccessCallback,
            'onFailureCallback' => $this->onFailureCallback,
            'onCompleteCallback' => $this->onCompleteCallback,
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
        $this->stepConfigs = $data['stepConfigs'] ?? [];
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
        $this->onSuccessCallback = $data['onSuccessCallback'] ?? null;
        $this->onFailureCallback = $data['onFailureCallback'] ?? null;
        $this->onCompleteCallback = $data['onCompleteCallback'] ?? null;
    }
}
