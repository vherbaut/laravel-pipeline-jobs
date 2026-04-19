<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineExecutor;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\CompensationInvoker;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\PipelineEventDispatcher;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepConditionEvaluator;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvocationDispatcher;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvoker;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Test executor that runs all steps synchronously while capturing
 * per-step context snapshots for assertion.
 *
 * Follows the same execution flow as SyncExecutor: iterates step classes
 * in order, instantiates via the container, injects the manifest, and
 * calls handle(). After each step completes, a deep clone of the current
 * context is stored in execution order.
 *
 * This executor lives in the Testing namespace and is only used when
 * Pipeline::fake()->recording() mode is active.
 *
 * Per-step queue, connection, and sync configuration is INERT in recording
 * mode. Steps run inline in the current process; `stepConfigs` is preserved
 * on the manifest so future assertion helpers can introspect the declared
 * routing without mutating execution behavior.
 *
 * Per-step `retry`, `backoff`, and `timeout` are also INERT in recording
 * mode. Each step is invoked exactly once via `app()->call([$job, 'handle'])`;
 * retry loops would double the recorded step entries and mislead assertions
 * like `assertStep(StepClass)->ran()`. The `stepConfigs` field is preserved
 * on the manifest so assertion helpers (future) can introspect the declared
 * retry / backoff / timeout policies.
 */
final class RecordingExecutor implements PipelineExecutor
{
    /** @var array<int, string> */
    private array $executedSteps = [];

    /** @var array<int, PipelineContext> */
    private array $contextSnapshots = [];

    private bool $compensationTriggered = false;

    /** @var array<int, string> */
    private array $compensationSteps = [];

    /**
     * Execute all steps synchronously, capturing context snapshots after each step.
     *
     * Mirrors SyncExecutor::execute() behaviorally: fires the three
     * per-step lifecycle hooks (beforeEach before handle(), afterEach
     * after successful handle() and before markStepCompleted(),
     * onStepFailed inside catch before compensation) AND the three
     * pipeline-level callbacks (onSuccess + onComplete on the
     * success tail, onFailure + onComplete on the failure path after
     * compensation) so tests using Pipeline::fake()->recording() observe
     * the same contract as SyncExecutor. Hook and callback side effects
     * precede the snapshot capture on the success path so a recorded
     * context reflects the post-hook state.
     *
     * @param PipelineDefinition $definition The immutable pipeline description containing steps and configuration.
     * @param PipelineManifest $manifest The mutable execution state carrying context and step progress.
     * @return PipelineContext|null The final pipeline context after execution, or null if no context was set.
     *
     * @throws StepExecutionFailed When any step throws an exception during execution.
     */
    public function execute(PipelineDefinition $definition, PipelineManifest $manifest): ?PipelineContext
    {
        foreach ($manifest->stepClasses as $stepIndex => $stepClass) {
            if (is_array($stepClass)) {
                $type = $stepClass['type'] ?? null;

                if ($type === 'nested') {
                    /** @var array<int, string|array<string, mixed>> $innerSteps */
                    $innerSteps = $stepClass['steps'] ?? [];

                    $groupConditions = $manifest->stepConditions[$stepIndex] ?? null;

                    /** @var array<int, array<string, mixed>|null> $innerConditionsEntries */
                    $innerConditionsEntries = (is_array($groupConditions) && ($groupConditions['type'] ?? null) === 'nested')
                        ? $groupConditions['entries']
                        : [];

                    $this->executeNestedPipeline($manifest, $stepIndex, $innerSteps, $innerConditionsEntries);

                    continue;
                }

                if ($type === 'branch') {
                    $this->executeConditionalBranch($manifest, $stepIndex, $stepClass);

                    continue;
                }

                /** @var array<int, string> $parallelClasses */
                $parallelClasses = $stepClass['classes'] ?? [];
                $this->executeParallelGroup($manifest, $stepIndex, $parallelClasses);

                continue;
            }

            try {
                if (StepConditionEvaluator::shouldSkipStep($manifest, $stepIndex)) {
                    $manifest->advanceStep();

                    continue;
                }

                $job = app()->make($stepClass);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                StepInvoker::fireHooks(
                    $manifest->beforeEachHooks,
                    StepDefinition::fromJobClass($stepClass),
                    $manifest->context,
                );

                StepInvocationDispatcher::call($job, $manifest->context);

                StepInvoker::fireHooks(
                    $manifest->afterEachHooks,
                    StepDefinition::fromJobClass($stepClass),
                    $manifest->context,
                );

                $manifest->markStepCompleted($stepClass);

                // RecordingExecutor mirrors SyncExecutor
                // event dispatch so Pipeline::fake()->recording() observes the
                // same event stream as production code. Fires after
                // markStepCompleted + afterEach hooks.
                PipelineEventDispatcher::fireStepCompleted($manifest, $stepIndex, $stepClass);

                $manifest->advanceStep();

                $this->executedSteps[] = $stepClass;

                if ($manifest->context !== null) {
                    $this->contextSnapshots[] = unserialize(serialize($manifest->context));
                }
            } catch (Throwable $exception) {
                $manifest->failureException = $exception;
                $manifest->failedStepClass = $stepClass;
                $manifest->failedStepIndex = $stepIndex;

                // PipelineStepFailed fires BEFORE onStepFailed hooks,
                // matching SyncExecutor ordering.
                PipelineEventDispatcher::fireStepFailed($manifest, $stepIndex, $stepClass, $exception);

                // A throwing onStepFailed bypasses the FailStrategy
                // branching (no compensation) and is wrapped
                // as StepExecutionFailed to mirror SyncExecutor (AC #10
                // recording/sync parity).
                try {
                    StepInvoker::fireHooks(
                        $manifest->onStepFailedHooks,
                        StepDefinition::fromJobClass($stepClass),
                        $manifest->context,
                        $exception,
                    );
                } catch (Throwable $hookException) {
                    throw StepExecutionFailed::forStep(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $stepClass,
                        $hookException,
                    );
                }

                // Under SkipAndContinue the
                // recording executor mirrors SyncExecutor: log the skip,
                // clear the live Throwable, advance past the failed step,
                // and resume with the next step. Pipeline-level onFailure
                // does NOT fire under SkipAndContinue; the loop reaches
                // the success tail and fires onSuccess + onComplete there.
                if ($manifest->failStrategy === FailStrategy::SkipAndContinue) {
                    Log::warning('Pipeline step skipped under SkipAndContinue', [
                        'pipelineId' => $manifest->pipelineId,
                        'stepClass' => $stepClass,
                        'stepIndex' => $stepIndex,
                        'exception' => $exception->getMessage(),
                    ]);

                    $manifest->failureException = null;
                    $manifest->advanceStep();

                    continue;
                }

                $this->runCompensation($manifest);

                // Pipeline-level onFailure fires AFTER per-step
                // onStepFailed AND AFTER compensation AND
                // BEFORE the terminal rethrow, mirroring SyncExecutor for
                // recording-mode parity.
                try {
                    StepInvoker::firePipelineCallback(
                        $manifest->onFailureCallback,
                        $manifest->context,
                        $exception,
                    );
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $stepClass,
                        $callbackException,
                        $exception,
                    );
                }

                try {
                    StepInvoker::firePipelineCallback($manifest->onCompleteCallback, $manifest->context);
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $stepClass,
                        $callbackException,
                        $exception,
                    );
                }

                // PipelineCompleted fires at the terminal failure exit
                // of the recording top-level flat-step path
                // (parity with SyncExecutor AC #8).
                PipelineEventDispatcher::fireCompleted($manifest);

                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $manifest->currentStepIndex,
                    $stepClass,
                    $exception,
                );
            }
        }

        // onSuccess fires on terminal success, then onComplete.
        // Recording-mode parity with SyncExecutor is load-bearing.
        StepInvoker::firePipelineCallback($manifest->onSuccessCallback, $manifest->context);
        StepInvoker::firePipelineCallback($manifest->onCompleteCallback, $manifest->context);

        // PipelineCompleted fires at the terminal success exit
        // AFTER the onSuccess + onComplete callbacks.
        PipelineEventDispatcher::fireCompleted($manifest);

        return $manifest->context;
    }

    /**
     * Replay a parallel group's sub-steps sequentially in recording mode.
     *
     * Mirrors SyncExecutor::executeParallelGroup() exactly so Pipeline::fake()->recording()
     * observes the same sequential-sync semantics (AC #14). Each sub-step's
     * completion is recorded in $this->executedSteps and a context snapshot
     * is captured after its successful run. Failure handling matches the
     * single-step failure path (onStepFailed, FailStrategy branching) with
     * the failing sub-step's class surfaced on the manifest.
     *
     * @param PipelineManifest $manifest The manifest driving execution state.
     * @param int $groupIndex The outer position of the parallel group.
     * @param array<int, string> $subStepClasses Sub-step class-strings in declaration order.
     * @return void
     *
     * @throws StepExecutionFailed When a sub-step fails under StopImmediately / StopAndCompensate.
     */
    private function executeParallelGroup(PipelineManifest $manifest, int $groupIndex, array $subStepClasses): void
    {
        $groupConditions = $manifest->stepConditions[$groupIndex] ?? null;

        /** @var array<int, array{closure: SerializableClosure, negated: bool}|null> $subConditions */
        $subConditions = (is_array($groupConditions) && ($groupConditions['type'] ?? null) === 'parallel')
            ? $groupConditions['entries']
            : [];

        foreach ($subStepClasses as $subIndex => $subStepClass) {
            try {
                $entry = $subConditions[$subIndex] ?? null;

                if ($entry !== null) {
                    $closure = $entry['closure']->getClosure();
                    $result = (bool) $closure($manifest->context);
                    $shouldRun = $entry['negated'] ? ! $result : $result;

                    if (! $shouldRun) {
                        continue;
                    }
                }

                $job = app()->make($subStepClass);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                StepInvoker::fireHooks(
                    $manifest->beforeEachHooks,
                    StepDefinition::fromJobClass($subStepClass),
                    $manifest->context,
                );

                StepInvocationDispatcher::call($job, $manifest->context);

                StepInvoker::fireHooks(
                    $manifest->afterEachHooks,
                    StepDefinition::fromJobClass($subStepClass),
                    $manifest->context,
                );

                $manifest->markStepCompleted($subStepClass);

                // Recording-mode parallel sub-steps fire PipelineStepCompleted
                // with the outer group index.
                PipelineEventDispatcher::fireStepCompleted($manifest, $groupIndex, $subStepClass);

                $this->executedSteps[] = $subStepClass;

                if ($manifest->context !== null) {
                    $this->contextSnapshots[] = unserialize(serialize($manifest->context));
                }
            } catch (Throwable $subException) {
                $manifest->failureException = $subException;
                $manifest->failedStepClass = $subStepClass;
                $manifest->failedStepIndex = $groupIndex;

                // Recording-mode parallel sub-step failure fires
                // PipelineStepFailed BEFORE hooks.
                PipelineEventDispatcher::fireStepFailed($manifest, $groupIndex, $subStepClass, $subException);

                try {
                    StepInvoker::fireHooks(
                        $manifest->onStepFailedHooks,
                        StepDefinition::fromJobClass($subStepClass),
                        $manifest->context,
                        $subException,
                    );
                } catch (Throwable $hookException) {
                    throw StepExecutionFailed::forStep(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $subStepClass,
                        $hookException,
                    );
                }

                if ($manifest->failStrategy === FailStrategy::SkipAndContinue) {
                    Log::warning('Pipeline parallel sub-step skipped under SkipAndContinue', [
                        'pipelineId' => $manifest->pipelineId,
                        'groupIndex' => $groupIndex,
                        'subStepClass' => $subStepClass,
                        'subStepIndex' => $subIndex,
                        'exception' => $subException->getMessage(),
                    ]);

                    $manifest->failureException = null;

                    continue;
                }

                $this->runCompensation($manifest);

                try {
                    StepInvoker::firePipelineCallback(
                        $manifest->onFailureCallback,
                        $manifest->context,
                        $subException,
                    );
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $subStepClass,
                        $callbackException,
                        $subException,
                    );
                }

                try {
                    StepInvoker::firePipelineCallback($manifest->onCompleteCallback, $manifest->context);
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $manifest->currentStepIndex,
                        $subStepClass,
                        $callbackException,
                        $subException,
                    );
                }

                // PipelineCompleted fires at the terminal failure exit
                // of a recording parallel sub-step.
                PipelineEventDispatcher::fireCompleted($manifest);

                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $manifest->currentStepIndex,
                    $subStepClass,
                    $subException,
                );
            }
        }

        $manifest->advanceStep();
    }

    /**
     * Replay a nested-pipeline group's inner steps sequentially in recording mode.
     *
     * Mirrors SyncExecutor::executeNestedPipeline() so Pipeline::fake()->recording()
     * observes the same sequential-sync semantics (AC #14). Each inner
     * step's completion is recorded in $this->executedSteps and a context
     * snapshot is captured after its successful run. Failure handling
     * matches the single-step failure path (onStepFailed, FailStrategy
     * branching) with the failing inner step's class surfaced on the
     * manifest; failedStepIndex records the ENCLOSING nested group's outer
     * position for downstream diagnostics.
     *
     * Parallel sub-groups inside the nested pipeline recurse through
     * executeParallelGroup() (parallel-inside-nested is supported per AC #1).
     * Nested-inside-nested entries recurse through this helper.
     *
     * The outer pipeline's hooks and callbacks govern; the inner
     * PipelineDefinition's own hook/callback slots are IGNORED (mirrors
     * SyncExecutor::executeNestedPipeline() design decisions 6 and 7).
     *
     * @param PipelineManifest $manifest The manifest driving execution state.
     * @param int $groupIndex The outer position of the nested group.
     * @param array<int, string|array<string, mixed>> $innerSteps Inner-step entries in declaration order: class-string, parallel shape, or nested shape.
     * @param array<int, array<string, mixed>|null> $innerConditionsEntries Per-inner-position condition entries aligned with $innerSteps.
     * @return void
     *
     * @throws StepExecutionFailed When an inner step fails under StopImmediately / StopAndCompensate.
     */
    private function executeNestedPipeline(
        PipelineManifest $manifest,
        int $groupIndex,
        array $innerSteps,
        array $innerConditionsEntries,
    ): void {
        foreach ($innerSteps as $subIndex => $entry) {
            $conditionEntry = $innerConditionsEntries[$subIndex] ?? null;

            if (is_array($entry)) {
                $entryType = $entry['type'] ?? null;

                if ($entryType === 'parallel') {
                    /** @var array<int, string> $subSubClasses */
                    $subSubClasses = $entry['classes'] ?? [];

                    $savedIndex = $manifest->currentStepIndex;
                    $manifest->currentStepIndex = $groupIndex;
                    $this->executeParallelGroup($manifest, $groupIndex, $subSubClasses);
                    // executeParallelGroup advances the outer index; inside a
                    // nested group we do NOT want that. Restore.
                    $manifest->currentStepIndex = $savedIndex;

                    continue;
                }

                if ($entryType === 'nested') {
                    /** @var array<int, string|array<string, mixed>> $innerInnerSteps */
                    $innerInnerSteps = $entry['steps'] ?? [];

                    /** @var array<int, array<string, mixed>|null> $innerInnerConditions */
                    $innerInnerConditions = (is_array($conditionEntry) && ($conditionEntry['type'] ?? null) === 'nested')
                        ? $conditionEntry['entries']
                        : [];

                    $this->executeNestedPipeline($manifest, $groupIndex, $innerInnerSteps, $innerInnerConditions);

                    continue;
                }

                if ($entryType === 'branch') {
                    $savedIndex = $manifest->currentStepIndex;
                    $manifest->currentStepIndex = $groupIndex;
                    $this->executeConditionalBranch($manifest, $groupIndex, $entry);
                    $manifest->currentStepIndex = $savedIndex;

                    continue;
                }

                throw new LogicException(
                    'RecordingExecutor::executeNestedPipeline encountered unknown inner-entry type '
                    .var_export($entryType, true).' at outer position '.$groupIndex.', inner position '.$subIndex.'.',
                );
            }

            try {
                /** @var array{closure: SerializableClosure, negated: bool}|null $flatConditionEntry */
                $flatConditionEntry = (is_array($conditionEntry) && ! isset($conditionEntry['type']))
                    ? $conditionEntry
                    : null;

                if ($flatConditionEntry !== null) {
                    $closure = $flatConditionEntry['closure']->getClosure();
                    $result = (bool) $closure($manifest->context);
                    $shouldRun = $flatConditionEntry['negated'] ? ! $result : $result;

                    if (! $shouldRun) {
                        continue;
                    }
                }

                $job = app()->make($entry);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                StepInvoker::fireHooks(
                    $manifest->beforeEachHooks,
                    StepDefinition::fromJobClass($entry),
                    $manifest->context,
                );

                StepInvocationDispatcher::call($job, $manifest->context);

                StepInvoker::fireHooks(
                    $manifest->afterEachHooks,
                    StepDefinition::fromJobClass($entry),
                    $manifest->context,
                );

                $manifest->markStepCompleted($entry);

                // Recording nested inner flat step fires PipelineStepCompleted
                // with the top outer group index.
                PipelineEventDispatcher::fireStepCompleted($manifest, $groupIndex, $entry);

                $this->executedSteps[] = $entry;

                if ($manifest->context !== null) {
                    $this->contextSnapshots[] = unserialize(serialize($manifest->context));
                }
            } catch (Throwable $innerException) {
                $cause = $innerException instanceof StepExecutionFailed
                    ? ($innerException->getPrevious() ?? $innerException)
                    : $innerException;

                $manifest->failureException = $cause;
                $manifest->failedStepClass = $entry;
                $manifest->failedStepIndex = $groupIndex;

                // Recording nested inner flat step fires PipelineStepFailed
                // BEFORE hooks.
                PipelineEventDispatcher::fireStepFailed($manifest, $groupIndex, $entry, $cause);

                try {
                    StepInvoker::fireHooks(
                        $manifest->onStepFailedHooks,
                        StepDefinition::fromJobClass($entry),
                        $manifest->context,
                        $cause,
                    );
                } catch (Throwable $hookException) {
                    throw StepExecutionFailed::forStep(
                        $manifest->pipelineId,
                        $groupIndex,
                        $entry,
                        $hookException,
                    );
                }

                if ($manifest->failStrategy === FailStrategy::SkipAndContinue) {
                    Log::warning('Pipeline nested inner step skipped under SkipAndContinue', [
                        'pipelineId' => $manifest->pipelineId,
                        'groupIndex' => $groupIndex,
                        'innerStepClass' => $entry,
                        'innerStepIndex' => $subIndex,
                        'exception' => $cause->getMessage(),
                    ]);

                    $manifest->failureException = null;

                    continue;
                }

                $this->runCompensation($manifest);

                try {
                    StepInvoker::firePipelineCallback(
                        $manifest->onFailureCallback,
                        $manifest->context,
                        $cause,
                    );
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $groupIndex,
                        $entry,
                        $callbackException,
                        $cause,
                    );
                }

                try {
                    StepInvoker::firePipelineCallback($manifest->onCompleteCallback, $manifest->context);
                } catch (Throwable $callbackException) {
                    throw StepExecutionFailed::forCallbackFailure(
                        $manifest->pipelineId,
                        $groupIndex,
                        $entry,
                        $callbackException,
                        $cause,
                    );
                }

                // PipelineCompleted fires at the terminal failure exit
                // of a recording nested inner step.
                PipelineEventDispatcher::fireCompleted($manifest);

                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $groupIndex,
                    $entry,
                    $cause,
                );
            }
        }

        $manifest->advanceStep();
    }

    /**
     * Execute a ConditionalBranch group at the given outer position in recording mode.
     *
     * Mirrors SyncExecutor::executeConditionalBranch() inline without the
     * full retry / sync-timeout machinery: evaluates the selector against
     * the context, looks up the matching branch value, and executes it in
     * place. Flat class-string branch values run as a single step (recorded
     * onto executedSteps + contextSnapshots); nested shapes recurse into
     * executeNestedPipeline(). Selector failures surface as
     * StepExecutionFailed::forStep() wrapping the root cause, matching the
     * sync-mode pipeline contract for assertion parity.
     *
     * @param PipelineManifest $manifest The manifest driving execution state.
     * @param int $groupIndex The outer position of the branch group.
     * @param array<string, mixed> $branchShape The resolved branch shape at that position.
     *
     * @return void
     *
     * @throws StepExecutionFailed When the selector throws, returns a non-string, or returns an unknown key.
     */
    private function executeConditionalBranch(PipelineManifest $manifest, int $groupIndex, array $branchShape): void
    {
        $nestedName = $branchShape['name'] ?? null;
        $branchWrapperLabel = $nestedName !== null ? "ConditionalBranch<{$nestedName}>" : 'ConditionalBranch';

        /** @var SerializableClosure $serializableSelector */
        $serializableSelector = $branchShape['selector'];

        try {
            $selectorClosure = $serializableSelector->getClosure();
            $selectedKey = $selectorClosure($manifest->context);
        } catch (Throwable $selectorException) {
            // Selector failure fires PipelineStepFailed with the
            // ConditionalBranch<> label.
            PipelineEventDispatcher::fireStepFailed($manifest, $groupIndex, $branchWrapperLabel, $selectorException);

            // PipelineCompleted fires at the terminal failure exit of a
            // recording selector failure (parity with sync mode's
            // SyncConditionalBranchRunner::handleSelectorFailure).
            PipelineEventDispatcher::fireCompleted($manifest);

            throw StepExecutionFailed::forStep(
                $manifest->pipelineId,
                $groupIndex,
                $branchWrapperLabel,
                $selectorException,
            );
        }

        if (! is_string($selectedKey)) {
            $nonStringFailure = InvalidPipelineDefinition::branchSelectorMustReturnString(get_debug_type($selectedKey));
            PipelineEventDispatcher::fireStepFailed($manifest, $groupIndex, $branchWrapperLabel, $nonStringFailure);

            // PipelineCompleted fires at the terminal failure exit of a
            // recording non-string selector return.
            PipelineEventDispatcher::fireCompleted($manifest);

            throw StepExecutionFailed::forStep(
                $manifest->pipelineId,
                $groupIndex,
                $branchWrapperLabel,
                $nonStringFailure,
            );
        }

        /** @var array<string, string|array<string, mixed>> $branches */
        $branches = $branchShape['branches'] ?? [];

        if (! array_key_exists($selectedKey, $branches)) {
            $unknownKeyFailure = InvalidPipelineDefinition::unknownBranchKey($selectedKey, array_keys($branches));
            PipelineEventDispatcher::fireStepFailed($manifest, $groupIndex, $branchWrapperLabel, $unknownKeyFailure);

            // PipelineCompleted fires at the terminal failure exit of a
            // recording unknown-key selector return.
            PipelineEventDispatcher::fireCompleted($manifest);

            throw StepExecutionFailed::forStep(
                $manifest->pipelineId,
                $groupIndex,
                $branchWrapperLabel,
                $unknownKeyFailure,
            );
        }

        $selectedValue = $branches[$selectedKey];

        if (is_array($selectedValue) && ($selectedValue['type'] ?? null) === 'nested') {
            /** @var array<int, string|array<string, mixed>> $innerSteps */
            $innerSteps = $selectedValue['steps'] ?? [];

            $branchConditions = $manifest->stepConditions[$groupIndex] ?? null;
            $branchConditionEntry = (is_array($branchConditions) && ($branchConditions['type'] ?? null) === 'branch')
                ? ($branchConditions['entries'][$selectedKey] ?? null)
                : null;

            /** @var array<int, array<string, mixed>|null> $innerConditions */
            $innerConditions = (is_array($branchConditionEntry) && ($branchConditionEntry['type'] ?? null) === 'nested')
                ? $branchConditionEntry['entries']
                : [];

            $this->executeNestedPipeline($manifest, $groupIndex, $innerSteps, $innerConditions);

            // executeNestedPipeline advanced past the outer; in the top-level branch case that's correct.
            return;
        }

        $selectedClass = $selectedValue;

        $branchConditions = $manifest->stepConditions[$groupIndex] ?? null;
        $branchConditionEntry = (is_array($branchConditions) && ($branchConditions['type'] ?? null) === 'branch')
            ? ($branchConditions['entries'][$selectedKey] ?? null)
            : null;

        /** @var array{closure: SerializableClosure, negated: bool}|null $flatConditionEntry */
        $flatConditionEntry = (is_array($branchConditionEntry) && ! isset($branchConditionEntry['type']))
            ? $branchConditionEntry
            : null;

        if ($flatConditionEntry !== null) {
            try {
                $result = (bool) ($flatConditionEntry['closure']->getClosure())($manifest->context);
            } catch (Throwable $conditionException) {
                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $groupIndex,
                    $selectedClass,
                    $conditionException,
                );
            }

            $shouldRun = $flatConditionEntry['negated'] ? ! $result : $result;

            if (! $shouldRun) {
                $manifest->advanceStep();

                return;
            }
        }

        try {
            $job = app()->make($selectedClass);

            if (property_exists($job, 'pipelineManifest')) {
                $property = new ReflectionProperty($job, 'pipelineManifest');
                $property->setValue($job, $manifest);
            }

            StepInvoker::fireHooks(
                $manifest->beforeEachHooks,
                StepDefinition::fromJobClass($selectedClass),
                $manifest->context,
            );

            StepInvocationDispatcher::call($job, $manifest->context);

            StepInvoker::fireHooks(
                $manifest->afterEachHooks,
                StepDefinition::fromJobClass($selectedClass),
                $manifest->context,
            );

            $manifest->markStepCompleted($selectedClass);

            // Recording selected branch flat step fires PipelineStepCompleted
            // with the branch group's outer index.
            PipelineEventDispatcher::fireStepCompleted($manifest, $groupIndex, $selectedClass);

            $manifest->advanceStep();

            $this->executedSteps[] = $selectedClass;

            if ($manifest->context !== null) {
                $this->contextSnapshots[] = unserialize(serialize($manifest->context));
            }
        } catch (Throwable $innerException) {
            $cause = $innerException instanceof StepExecutionFailed
                ? ($innerException->getPrevious() ?? $innerException)
                : $innerException;

            $manifest->failureException = $cause;
            $manifest->failedStepClass = $selectedClass;
            $manifest->failedStepIndex = $groupIndex;

            // Recording selected branch flat step failure fires PipelineStepFailed.
            PipelineEventDispatcher::fireStepFailed($manifest, $groupIndex, $selectedClass, $cause);

            try {
                StepInvoker::fireHooks(
                    $manifest->onStepFailedHooks,
                    StepDefinition::fromJobClass($selectedClass),
                    $manifest->context,
                    $cause,
                );
            } catch (Throwable $hookException) {
                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $groupIndex,
                    $selectedClass,
                    $hookException,
                );
            }

            if ($manifest->failStrategy === FailStrategy::SkipAndContinue) {
                $manifest->failureException = null;
                $manifest->advanceStep();

                return;
            }

            $this->runCompensation($manifest);

            try {
                StepInvoker::firePipelineCallback(
                    $manifest->onFailureCallback,
                    $manifest->context,
                    $cause,
                );
            } catch (Throwable $callbackException) {
                throw StepExecutionFailed::forCallbackFailure(
                    $manifest->pipelineId,
                    $groupIndex,
                    $selectedClass,
                    $callbackException,
                    $cause,
                );
            }

            try {
                StepInvoker::firePipelineCallback($manifest->onCompleteCallback, $manifest->context);
            } catch (Throwable $callbackException) {
                throw StepExecutionFailed::forCallbackFailure(
                    $manifest->pipelineId,
                    $groupIndex,
                    $selectedClass,
                    $callbackException,
                    $cause,
                );
            }

            // PipelineCompleted fires at the terminal failure exit of a
            // recording selected branch's flat step.
            PipelineEventDispatcher::fireCompleted($manifest);

            throw StepExecutionFailed::forStep(
                $manifest->pipelineId,
                $groupIndex,
                $selectedClass,
                $cause,
            );
        }
    }

    /**
     * Get the ordered list of step class names that completed execution.
     *
     * @return array<int, string> Fully qualified class names in execution order.
     */
    public function executedSteps(): array
    {
        return $this->executedSteps;
    }

    /**
     * Get the per-step context snapshots captured during execution.
     *
     * Each snapshot is a deep clone of the context as it was immediately
     * after the corresponding step completed, stored in execution order.
     *
     * @return array<int, PipelineContext> Snapshots in execution order.
     */
    public function contextSnapshots(): array
    {
        return $this->contextSnapshots;
    }

    /**
     * Check whether compensation was triggered during execution.
     *
     * @return bool True if at least one compensation job was executed.
     */
    public function compensationTriggered(): bool
    {
        return $this->compensationTriggered;
    }

    /**
     * Get the ordered list of compensation job class names that were executed.
     *
     * @return array<int, string> Compensation classes in execution order (reverse of completed steps).
     */
    public function compensationSteps(): array
    {
        return $this->compensationSteps;
    }

    /**
     * Run compensation jobs for completed steps in reverse order.
     *
     * Guarded on the manifest's failStrategy: only fires when strategy is
     * FailStrategy::StopAndCompensate AND a compensation mapping exists.
     * StopImmediately and SkipAndContinue both skip compensation entirely
     * (SkipAndContinue handles failures per-step without running compensation).
     *
     * Uses the CompensableJob-or-trait bridge: instances implementing
     * CompensableJob receive a compensate($context) call; otherwise the
     * legacy pattern fires (reflection-injected manifest plus
     * app()->call([$job, 'handle'])). Per-compensation failures are
     * silently swallowed so the chain continues.
     *
     * @param PipelineManifest $manifest The pipeline manifest carrying completed steps, compensation mapping, context, and strategy.
     *
     * @return void
     */
    private function runCompensation(PipelineManifest $manifest): void
    {
        if ($manifest->compensationMapping === [] || $manifest->failStrategy !== FailStrategy::StopAndCompensate) {
            return;
        }

        $reversedCompleted = array_reverse($manifest->completedSteps);
        $failureContext = FailureContext::fromManifest($manifest);

        foreach ($reversedCompleted as $completedStep) {
            if (! isset($manifest->compensationMapping[$completedStep])) {
                continue;
            }

            $compensationClass = $manifest->compensationMapping[$completedStep];

            try {
                $job = app()->make($compensationClass);

                if ($job instanceof CompensableJob) {
                    CompensationInvoker::invokeCompensate($job, $manifest->context, $failureContext);
                } else {
                    if (property_exists($job, 'pipelineManifest')) {
                        $property = new ReflectionProperty($job, 'pipelineManifest');
                        $property->setValue($job, $manifest);
                    }

                    // Compensation paths remain on the legacy app()->call() contract;
                    // middleware/Action support for compensation
                    // is OUT OF SCOPE.
                    app()->call([$job, 'handle']);
                }

                $this->compensationSteps[] = $compensationClass;
            } catch (Throwable $compensationException) {
                $this->compensationSteps[] = $compensationClass;
                CompensationInvoker::reportCompensationFailure(
                    $manifest,
                    $compensationClass,
                    $manifest->failureException,
                    $compensationException,
                );
            }
        }

        if ($this->compensationSteps !== []) {
            $this->compensationTriggered = true;
        }
    }
}
