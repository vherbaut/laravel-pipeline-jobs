<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Context;

use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use ReflectionClass;
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
     * Cursor path into a nested-pipeline position, empty when executing at the outer level.
     *
     * Linear path-array representing the current position inside a
     * NestedPipeline hierarchy. Each element is an integer index into the
     * enclosing level's steps array:
     *  - `[]` — not inside any nested group; $currentStepIndex names the
     *    executing outer step.
     *  - `[p, i]` — executing inner step i of the nested group at outer
     *    position p.
     *  - `[p, i, j]` — executing inner-inner step j of the nested group at
     *    inner position i of the nested group at outer position p.
     *
     * Supports arbitrary-depth nesting: each level of nesting appends one
     * element to the path; stepClassAt() navigates N levels by recursing
     * through `['steps']` keys. Cleared to `[]` when the nested group
     * completes and execution returns to the outer level.
     *
     * Serialized in __serialize() / __unserialize() so queued nested
     * execution can resume across worker hops. Legacy payloads (predating
     * Story 8.2) default the field to `[]` on unserialize, matching the
     * defensive ?? pattern used for other post-Story-1 fields.
     *
     * @var array<int, int>
     */
    public array $nestedCursor = [];

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
     * @param array<int, string|array<string, mixed>> $stepClasses Ordered list of job class names. Parallel-group positions carry a nested `['type' => 'parallel', 'classes' => array<int, string>]` shape in lieu of a flat class-string.
     * @param array<string, string> $compensationMapping Map of step class name to compensation class name.
     * @param array<int, array<string, mixed>> $stepConditions Per-step condition entries keyed by step index. Parallel groups carry a nested `['type' => 'parallel', 'entries' => [...]]` shape where each inner entry is the flat shape or null for an unconditional sub-step.
     * @param int $currentStepIndex Index of the current step being executed.
     * @param array<int, string> $completedSteps List of completed step class names.
     * @param PipelineContext|null $context The user's pipeline context DTO.
     * @param FailStrategy $failStrategy Saga failure strategy propagated from the PipelineDefinition so queued executors can decide whether to trigger compensation after a step failure.
     * @param array<int, array<string, mixed>> $stepConfigs Per-step resolved queue / connection / sync / retry count / backoff delay (seconds) / wrapper timeout (seconds) configuration indexed by step position. Parallel groups carry a nested `['type' => 'parallel', 'configs' => [...]]` shape where each inner entry is the flat config shape per sub-step.
     */
    public function __construct(
        public readonly string $pipelineId,
        public readonly ?string $pipelineName,
        /** @var array<int, string|array<string, mixed>> */
        public readonly array $stepClasses,
        /** @var array<string, string> */
        public readonly array $compensationMapping,
        /** @var array<int, array<string, mixed>> */
        public readonly array $stepConditions,
        public int $currentStepIndex,
        /** @var array<int, string> */
        public array $completedSteps,
        public ?PipelineContext $context,
        public FailStrategy $failStrategy = FailStrategy::StopImmediately,
        /** @var array<int, array<string, mixed>> */
        public readonly array $stepConfigs = [],
    ) {}

    /**
     * Create a new pipeline manifest with auto-generated UUID and default execution state.
     *
     * @param array<int, string|array<string, mixed>> $stepClasses Ordered list of job class names; parallel positions carry a nested shape.
     * @param PipelineContext|null $context The user's pipeline context DTO.
     * @param array<string, string> $compensationMapping Map of step class name to compensation class name.
     * @param string|null $pipelineName Optional human-readable name for this pipeline.
     * @param array<int, array<string, mixed>> $stepConditions Per-step condition entries keyed by step index; parallel groups carry a nested shape.
     * @param FailStrategy $failStrategy Saga failure strategy propagated from the PipelineDefinition so executors can decide whether to trigger compensation after a step failure.
     * @param array<int, array<string, mixed>> $stepConfigs Per-step resolved queue / connection / sync / retry / backoff / timeout configuration indexed by step position; parallel positions carry a nested shape.
     *
     * @return self
     */
    public static function create(
        /** @var array<int, string|array<string, mixed>> */
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
            'nestedCursor' => $this->nestedCursor,
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
        $this->nestedCursor = $data['nestedCursor'] ?? [];
    }

    /**
     * Produce a deep-cloned manifest whose stepConfigs[$index] has been replaced with the given entry.
     *
     * The readonly $stepConfigs property forbids in-place mutation after
     * construction, so this helper re-hydrates a fresh instance through the
     * __serialize / __unserialize lifecycle with the replacement entry
     * substituted in the serialized array. All other readonly and mutable
     * state (hooks, callbacks, context, currentStepIndex, completedSteps,
     * nestedCursor, etc.) is deep-cloned via serialize / unserialize so
     * sub-step workers operate on independent state.
     *
     * Intended caller: PipelineStepJob::dispatchParallelBatch() when a
     * parallel group sits inside a NestedPipeline. The outer manifest's
     * stepConfigs[$cursor[0]] is a nested shape; the effective parallel
     * shape lives at stepConfigAt($cursor). ParallelStepJob (a
     * forbidden-edit file) reads stepConfigs[$groupIndex] via a flat
     * lookup, so each cloned manifest has its stepConfigs[$groupIndex]
     * re-keyed to the parallel shape to keep per-sub-step retry / backoff
     * resolution intact for parallel-inside-nested.
     *
     * @param int $index The zero-based outer position whose stepConfigs entry is replaced.
     * @param array<string, mixed> $entry The replacement stepConfigs entry at that position.
     *
     * @return self A deep-cloned manifest with the replacement applied.
     */
    public function withRekeyedStepConfig(int $index, array $entry): self
    {
        $data = $this->__serialize();
        $data['stepConfigs'][$index] = $entry;

        /** @var self $rehydrated */
        $rehydrated = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        /** @var array<string, mixed> $deepCloned */
        $deepCloned = unserialize(serialize($data));
        $rehydrated->__unserialize($deepCloned);

        return $rehydrated;
    }

    /**
     * Navigate the nested-stepClasses tree to resolve the entry at the given cursor path.
     *
     * Empty path returns the top-level entry at the current $currentStepIndex
     * (behaves as a no-op lookup for non-nested execution). A one-element
     * path `[p]` returns stepClasses[p] verbatim (used sparingly: the cursor
     * is cleared to `[]` once execution returns to the outer level, so the
     * single-element form mostly surfaces in transitional state). Longer
     * paths recurse: the second element indexes into stepClasses[p]['steps']
     * if the entry is a nested shape OR stepClasses[p]['classes'] if the
     * entry is a parallel shape. The navigation stops when it lands on a
     * class-string or parallel-shape leaf; further path elements beyond the
     * nested-to-flat transition are a programmer error and throw
     * LogicException with a diagnostic message.
     *
     * @param array<int, int> $path Cursor path from outer-root down to the target entry. Empty path resolves to the current outer index.
     *
     * @return string|array<string, mixed> The resolved stepClasses entry: class-string for a flat step, parallel-shape array for a parallel sub-group, or nested-shape array for a nested sub-pipeline.
     *
     * @throws LogicException When the cursor path cannot be navigated (e.g., an intermediate segment lands on a non-navigable entry or the outer index is out of range).
     */
    public function stepClassAt(array $path): string|array
    {
        if ($path === []) {
            if (! array_key_exists($this->currentStepIndex, $this->stepClasses)) {
                throw new LogicException(
                    'PipelineManifest::stepClassAt called with empty path but currentStepIndex '
                    .$this->currentStepIndex.' is out of range for stepClasses array of size '
                    .count($this->stepClasses).'.',
                );
            }

            return $this->stepClasses[$this->currentStepIndex];
        }

        $rootIndex = $path[0];

        if (! array_key_exists($rootIndex, $this->stepClasses)) {
            throw new LogicException(
                'PipelineManifest::stepClassAt cursor path root index '.$rootIndex
                .' is out of range for stepClasses array of size '.count($this->stepClasses).'.',
            );
        }

        $current = $this->stepClasses[$rootIndex];
        $pathLength = count($path);

        for ($depth = 1; $depth < $pathLength; $depth++) {
            $segment = $path[$depth];

            if (! is_array($current)) {
                throw new LogicException(
                    'PipelineManifest::stepClassAt cannot descend into segment '.$segment
                    .' at depth '.$depth.': reached a non-array entry (likely a class-string).',
                );
            }

            $type = $current['type'] ?? null;

            if ($type === 'nested') {
                /** @var array<int, string|array<string, mixed>> $innerSteps */
                $innerSteps = $current['steps'] ?? [];

                if (! array_key_exists($segment, $innerSteps)) {
                    throw new LogicException(
                        'PipelineManifest::stepClassAt nested inner index '.$segment
                        .' is out of range at depth '.$depth.'.',
                    );
                }

                $current = $innerSteps[$segment];

                continue;
            }

            if ($type === 'parallel') {
                /** @var array<int, string> $parallelClasses */
                $parallelClasses = $current['classes'] ?? [];

                if (! array_key_exists($segment, $parallelClasses)) {
                    throw new LogicException(
                        'PipelineManifest::stepClassAt parallel inner index '.$segment
                        .' is out of range at depth '.$depth.'.',
                    );
                }

                $current = $parallelClasses[$segment];

                continue;
            }

            throw new LogicException(
                'PipelineManifest::stepClassAt cannot descend into entry with unknown type '
                .var_export($type, true).' at depth '.$depth.'.',
            );
        }

        return $current;
    }

    /**
     * Navigate the nested-stepConfigs tree to resolve the config entry at the given cursor path.
     *
     * Mirrors stepClassAt() but traverses $stepConfigs via `['configs']`
     * at both nested and parallel levels (stepConfigs uses `configs` for
     * both parallel sub-entries and nested inner entries). Returns the
     * resolved config: a flat config array for a single step, OR a
     * parallel / nested discriminator-tagged array for a sub-group
     * position. Returns an empty array when the manifest is legacy or the
     * path cannot be navigated; callers merge with a default-config shape.
     *
     * @param array<int, int> $path Cursor path from outer-root down to the target entry. Empty path resolves to the current outer index.
     *
     * @return array<string, mixed> The resolved stepConfigs entry (flat config or discriminator-tagged sub-shape); empty array when legacy/unavailable.
     */
    public function stepConfigAt(array $path): array
    {
        if ($path === []) {
            $entry = $this->stepConfigs[$this->currentStepIndex] ?? null;

            return is_array($entry) ? $entry : [];
        }

        $rootIndex = $path[0];
        $entry = $this->stepConfigs[$rootIndex] ?? null;

        if (! is_array($entry)) {
            return [];
        }

        $current = $entry;
        $pathLength = count($path);

        for ($depth = 1; $depth < $pathLength; $depth++) {
            $segment = $path[$depth];
            $type = $current['type'] ?? null;

            if ($type !== 'nested' && $type !== 'parallel') {
                return [];
            }

            /** @var array<int, array<string, mixed>> $innerConfigs */
            $innerConfigs = $current['configs'] ?? [];

            if (! array_key_exists($segment, $innerConfigs)) {
                return [];
            }

            $current = $innerConfigs[$segment];
        }

        return $current;
    }

    /**
     * Navigate the nested-stepConditions tree to resolve the condition entry at the given cursor path.
     *
     * Mirrors stepConfigAt() but traverses $stepConditions via `['entries']`
     * at both nested and parallel levels. Returns the resolved condition
     * entry (a flat `['closure' => ..., 'negated' => ...]` shape), or null
     * when the entry is absent / unconditional / group-shaped (group-shaped
     * entries are handled by the enclosing dispatcher, not evaluated as a
     * per-step condition here).
     *
     * @param array<int, int> $path Cursor path from outer-root down to the target entry. Empty path resolves to the current outer index.
     *
     * @return array{closure: SerializableClosure, negated: bool}|null The resolved flat condition entry or null when unconditional / group-shaped.
     */
    public function conditionAt(array $path): ?array
    {
        if ($path === []) {
            $entry = $this->stepConditions[$this->currentStepIndex] ?? null;

            if (! is_array($entry)) {
                return null;
            }

            if (isset($entry['type'])) {
                return null;
            }

            /** @var array{closure: SerializableClosure, negated: bool} $entry */
            return $entry;
        }

        $rootIndex = $path[0];
        $entry = $this->stepConditions[$rootIndex] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $current = $entry;
        $pathLength = count($path);

        for ($depth = 1; $depth < $pathLength; $depth++) {
            $segment = $path[$depth];

            if ($current === null) {
                return null;
            }

            $type = $current['type'] ?? null;

            if ($type !== 'nested' && $type !== 'parallel') {
                return null;
            }

            /** @var array<int, array<string, mixed>|null> $innerEntries */
            $innerEntries = $current['entries'] ?? [];

            $current = array_key_exists($segment, $innerEntries) ? $innerEntries[$segment] : null;
        }

        if ($current === null) {
            return null;
        }

        if (isset($current['type'])) {
            return null;
        }

        /** @var array{closure: SerializableClosure, negated: bool} $current */
        return $current;
    }
}
