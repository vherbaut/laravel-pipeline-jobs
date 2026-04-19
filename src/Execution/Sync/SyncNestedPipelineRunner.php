<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Sync;

use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\PipelineEventDispatcher;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepConditionEvaluator;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvoker;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Synchronous runner for a nested-pipeline group.
 *
 * Inner steps share the OUTER PipelineContext instance (mutations by an
 * earlier inner step are visible to later inner steps AND to outer steps
 * after the group completes) and contribute to the flat
 * `$manifest->completedSteps` list by their own class names (reverse-order
 * compensation over StopAndCompensate spans inner + outer entries). Parallel
 * sub-groups inside the nested pipeline fan out sequentially in sync mode via
 * {@see SyncParallelGroupRunner::executeEntries()}. Nested-nested entries
 * recurse through {@see self::run()}. Conditional-branch inner entries
 * delegate to {@see SyncConditionalBranchRunner::run()} with advanceOuter=false.
 *
 * OUTER pipeline hooks (beforeEach / afterEach / onStepFailed) fire per inner
 * step. OUTER pipeline-level callbacks fire once at the OUTER terminal. The
 * inner PipelineDefinition's own hook/callback slots are IGNORED. The OUTER
 * pipeline's FailStrategy governs.
 *
 * @internal
 */
final class SyncNestedPipelineRunner
{
    /**
     * Execute a nested-pipeline group at the given outer position.
     *
     * Advances the outer position exactly once at the terminal.
     *
     * @param PipelineManifest $manifest The mutable manifest carrying context, completedSteps, and per-step conditions/configs.
     * @param int $groupIndex The outer position of the nested group in the pipeline (used for observability on failure).
     * @param array<int, string|array<string, mixed>> $innerSteps Inner-step entries in declaration order: class-string, parallel shape, nested shape, or branch shape.
     * @param string|null $nestedName Optional user-visible sub-pipeline name for observability (currently surfaced via log context only).
     * @param array<int, array<string, mixed>|null> $innerConditionsEntries Per-inner-position condition entries aligned with $innerSteps; each entry is null, a flat condition shape, a parallel-sub shape, or a nested-sub shape.
     * @param array<int, array<string, mixed>> $innerConfigsEntries Per-inner-position resolved configs aligned with $innerSteps; each entry is a flat config shape, a parallel-sub shape, or a nested-sub shape.
     * @return void
     *
     * @throws StepExecutionFailed When an inner step fails under StopImmediately or StopAndCompensate (or when a hook or callback re-throws).
     */
    public static function run(
        PipelineManifest $manifest,
        int $groupIndex,
        array $innerSteps,
        ?string $nestedName,
        array $innerConditionsEntries,
        array $innerConfigsEntries,
    ): void {
        $defaultConfig = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

        foreach ($innerSteps as $subIndex => $entry) {
            $conditionEntry = $innerConditionsEntries[$subIndex] ?? null;
            $configEntry = $innerConfigsEntries[$subIndex] ?? null;

            if (is_array($entry)) {
                $entryType = $entry['type'] ?? null;

                if ($entryType === 'parallel') {
                    /** @var array<int, string> $subSubClasses */
                    $subSubClasses = $entry['classes'] ?? [];

                    /** @var array<int, array<string, mixed>|null> $subSubConditions */
                    $subSubConditions = (is_array($conditionEntry) && ($conditionEntry['type'] ?? null) === 'parallel')
                        ? $conditionEntry['entries']
                        : [];

                    /** @var array<int, array<string, mixed>> $subSubConfigs */
                    $subSubConfigs = (is_array($configEntry) && ($configEntry['type'] ?? null) === 'parallel')
                        ? $configEntry['configs']
                        : [];

                    SyncParallelGroupRunner::executeEntries(
                        $manifest,
                        $groupIndex,
                        $subSubClasses,
                        $subSubConditions,
                        $subSubConfigs,
                    );

                    continue;
                }

                if ($entryType === 'nested') {
                    /** @var array<int, string|array<string, mixed>> $innerInnerSteps */
                    $innerInnerSteps = $entry['steps'] ?? [];

                    /** @var array<int, array<string, mixed>|null> $innerInnerConditions */
                    $innerInnerConditions = (is_array($conditionEntry) && ($conditionEntry['type'] ?? null) === 'nested')
                        ? $conditionEntry['entries']
                        : [];

                    /** @var array<int, array<string, mixed>> $innerInnerConfigs */
                    $innerInnerConfigs = (is_array($configEntry) && ($configEntry['type'] ?? null) === 'nested')
                        ? $configEntry['configs']
                        : [];

                    self::run(
                        $manifest,
                        $groupIndex,
                        $innerInnerSteps,
                        $entry['name'] ?? null,
                        $innerInnerConditions,
                        $innerInnerConfigs,
                    );

                    continue;
                }

                if ($entryType === 'branch') {
                    SyncConditionalBranchRunner::run($manifest, $groupIndex, $entry, advanceOuter: false);

                    continue;
                }

                // Unknown shape; treat defensively as a logic error so we surface it rather than silently skip.
                throw new LogicException(
                    'SyncNestedPipelineRunner encountered unknown inner-entry type '
                    .var_export($entryType, true).' at outer position '.$groupIndex.', inner position '.$subIndex.'.',
                );
            }

            // Flat inner step body (string class-name).
            try {
                /** @var array{closure: SerializableClosure, negated: bool}|null $flatConditionEntry */
                $flatConditionEntry = (is_array($conditionEntry) && ! isset($conditionEntry['type']))
                    ? $conditionEntry
                    : null;
                // Legacy defensive fallback: if the condition entry is itself a group shape we ignore it here.

                $shouldSkip = StepConditionEvaluator::shouldSkipEntry($flatConditionEntry, $manifest->context);
            } catch (Throwable $conditionException) {
                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $groupIndex,
                    $entry,
                    $conditionException,
                );
            }

            if ($shouldSkip) {
                continue;
            }

            try {
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

                /** @var array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} $flatConfig */
                $flatConfig = (is_array($configEntry) && ! isset($configEntry['type']))
                    ? $configEntry
                    : $defaultConfig;

                StepInvoker::invokeWithRetry($job, $flatConfig);

                StepInvoker::fireHooks(
                    $manifest->afterEachHooks,
                    StepDefinition::fromJobClass($entry),
                    $manifest->context,
                );

                $manifest->markStepCompleted($entry);

                // Story 9.1 AC #11: nested inner flat steps fire
                // PipelineStepCompleted with $stepIndex = the TOP outer group
                // index so listeners correlate against the user-visible outer
                // position. $groupIndex here is always the top-level index
                // (the recursive self::run() call preserves it).
                PipelineEventDispatcher::fireStepCompleted($manifest, $groupIndex, $entry);

                $manifest->failureException = null;
                $manifest->failedStepClass = null;
                $manifest->failedStepIndex = null;
            } catch (Throwable $innerException) {
                // Collapse double-wrapping: if an inner step itself runs another pipeline
                // that threw StepExecutionFailed, unwrap to the underlying step exception
                // so the outer frame wraps ONCE.
                $cause = $innerException instanceof StepExecutionFailed
                    ? ($innerException->getPrevious() ?? $innerException)
                    : $innerException;

                $manifest->failureException = $cause;
                $manifest->failedStepClass = $entry;
                $manifest->failedStepIndex = $groupIndex;

                // Story 9.1 AC #11: nested inner flat steps fire
                // PipelineStepFailed with $stepIndex = the top outer index
                // BEFORE onStepFailed hooks so listeners observe the RAW
                // failure even when a throwing hook replaces it.
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
                        'nestedName' => $nestedName,
                        'innerStepClass' => $entry,
                        'innerStepIndex' => $subIndex,
                        'exception' => $cause->getMessage(),
                    ]);

                    $manifest->failureException = null;

                    continue;
                }

                if ($manifest->failStrategy === FailStrategy::StopAndCompensate) {
                    try {
                        SyncCompensationRunner::run($manifest);
                    } catch (Throwable $compensationException) {
                        Log::error('Pipeline compensation chain failed during nested group rollback', [
                            'pipelineId' => $manifest->pipelineId,
                            'groupIndex' => $groupIndex,
                            'nestedName' => $nestedName,
                            'innerStepClass' => $entry,
                            'compensationException' => $compensationException->getMessage(),
                            'originalException' => $cause->getMessage(),
                        ]);
                    }
                }

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

                // Story 9.1 AC #8: PipelineCompleted fires at the terminal
                // failure exit of a nested inner step, AFTER onFailure +
                // onComplete callbacks and BEFORE the StepExecutionFailed
                // rethrow.
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
}
