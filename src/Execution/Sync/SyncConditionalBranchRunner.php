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
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\PipelineEventDispatcher;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepConditionEvaluator;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvoker;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Synchronous runner for a conditional-branch group.
 *
 * Evaluates the selector once against the live context, resolves the selected
 * branch value, and executes it in place of the branch position. For a flat
 * class-string branch value this runs the single step inline. For a nested-
 * shape branch value this delegates to {@see SyncNestedPipelineRunner::run()}.
 *
 * The outer position advances EXACTLY ONCE at the terminal when $advanceOuter
 * is true (the default for top-level branch positions). When invoked from
 * {@see SyncNestedPipelineRunner} as an inner branch entry, $advanceOuter is
 * false because the enclosing nested group's advance happens at its own
 * terminal.
 *
 * Selector failures (thrown, non-string return, unknown key) surface as
 * StepExecutionFailed wrapping the root cause so the standard FailStrategy
 * branching applies uniformly across branch and single-step failures.
 *
 * @internal
 */
final class SyncConditionalBranchRunner
{
    /**
     * Execute a conditional branch group at the given outer position.
     *
     * @param PipelineManifest $manifest The mutable execution state.
     * @param int $groupIndex The outer position of the branch group.
     * @param array<string, mixed> $branchShape The resolved branch shape at that position (`['type' => 'branch', 'name' => ?, 'selector' => SerializableClosure, 'branches' => [...]]`).
     * @param bool $advanceOuter Whether to advance the outer position after the selected branch completes; true by default, false when invoked as an inner entry of a nested group.
     * @return void
     *
     * @throws StepExecutionFailed When the selector throws, returns a non-string, returns an unknown key, or the selected branch's inner step fails under StopImmediately / StopAndCompensate.
     */
    public static function run(
        PipelineManifest $manifest,
        int $groupIndex,
        array $branchShape,
        bool $advanceOuter = true,
    ): void {
        $nestedName = $branchShape['name'] ?? null;
        $branchWrapperLabel = $nestedName !== null ? "ConditionalBranch<{$nestedName}>" : 'ConditionalBranch';

        /** @var SerializableClosure $serializableSelector */
        $serializableSelector = $branchShape['selector'];
        $selectorClosure = $serializableSelector->getClosure();

        try {
            $selectedKey = $selectorClosure($manifest->context);
        } catch (Throwable $selectorException) {
            self::handleSelectorFailure($manifest, $groupIndex, $branchWrapperLabel, $selectorException, $nestedName, $advanceOuter);

            return;
        }

        if (! is_string($selectedKey)) {
            self::handleSelectorFailure(
                $manifest,
                $groupIndex,
                $branchWrapperLabel,
                InvalidPipelineDefinition::branchSelectorMustReturnString(get_debug_type($selectedKey)),
                $nestedName,
                $advanceOuter,
            );

            return;
        }

        /** @var array<string, string|array<string, mixed>> $branches */
        $branches = $branchShape['branches'] ?? [];

        if (! array_key_exists($selectedKey, $branches)) {
            self::handleSelectorFailure(
                $manifest,
                $groupIndex,
                $branchWrapperLabel,
                InvalidPipelineDefinition::unknownBranchKey($selectedKey, array_keys($branches)),
                $nestedName,
                $advanceOuter,
            );

            return;
        }

        $selectedValue = $branches[$selectedKey];

        $branchConditions = $manifest->stepConditions[$groupIndex] ?? null;
        $branchConfigs = $manifest->stepConfigs[$groupIndex] ?? null;

        /** @var array<string, array<string, mixed>|null> $allConditions */
        $allConditions = (is_array($branchConditions) && ($branchConditions['type'] ?? null) === 'branch')
            ? $branchConditions['entries']
            : [];
        $conditionEntry = $allConditions[$selectedKey] ?? null;

        /** @var array<string, array<string, mixed>> $allConfigs */
        $allConfigs = (is_array($branchConfigs) && ($branchConfigs['type'] ?? null) === 'branch')
            ? $branchConfigs['configs']
            : [];
        $configEntry = $allConfigs[$selectedKey] ?? null;

        if (is_array($selectedValue)) {
            $selectedType = $selectedValue['type'] ?? null;

            if ($selectedType === 'nested') {
                /** @var array<int, string|array<string, mixed>> $innerSteps */
                $innerSteps = $selectedValue['steps'] ?? [];

                /** @var array<int, array<string, mixed>|null> $innerConditions */
                $innerConditions = (is_array($conditionEntry) && ($conditionEntry['type'] ?? null) === 'nested')
                    ? $conditionEntry['entries']
                    : [];

                /** @var array<int, array<string, mixed>> $innerConfigs */
                $innerConfigs = (is_array($configEntry) && ($configEntry['type'] ?? null) === 'nested')
                    ? $configEntry['configs']
                    : [];

                SyncNestedPipelineRunner::run(
                    $manifest,
                    $groupIndex,
                    $innerSteps,
                    $selectedValue['name'] ?? null,
                    $innerConditions,
                    $innerConfigs,
                );

                // SyncNestedPipelineRunner advances the outer position at its terminal.
                // For an inner branch inside a nested group ($advanceOuter === false),
                // the enclosing nested group will advance at its own terminal; undo
                // the extra advance here.
                if (! $advanceOuter) {
                    $manifest->currentStepIndex--;
                }

                return;
            }

            throw new LogicException(
                'SyncConditionalBranchRunner resolved an unsupported branch value shape '
                .var_export($selectedType, true).' at outer position '.$groupIndex.' for key "'.$selectedKey.'".',
            );
        }

        $selectedClass = $selectedValue;

        /** @var array{closure: SerializableClosure, negated: bool}|null $flatConditionEntry */
        $flatConditionEntry = (is_array($conditionEntry) && ! isset($conditionEntry['type']))
            ? $conditionEntry
            : null;

        try {
            $shouldSkip = StepConditionEvaluator::shouldSkipEntry($flatConditionEntry, $manifest->context);
        } catch (Throwable $conditionException) {
            throw StepExecutionFailed::forStep(
                $manifest->pipelineId,
                $groupIndex,
                $selectedClass,
                $conditionException,
            );
        }

        if ($shouldSkip) {
            if ($advanceOuter) {
                $manifest->advanceStep();
            }

            return;
        }

        $defaultConfig = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

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

            /** @var array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int} $flatConfig */
            $flatConfig = (is_array($configEntry) && ! isset($configEntry['type']))
                ? $configEntry
                : $defaultConfig;

            StepInvoker::invokeWithRetry($job, $flatConfig);

            StepInvoker::fireHooks(
                $manifest->afterEachHooks,
                StepDefinition::fromJobClass($selectedClass),
                $manifest->context,
            );

            $manifest->markStepCompleted($selectedClass);

            // Story 9.1 AC #12: only the selected branch path's flat inner step
            // fires PipelineStepCompleted; non-selected branches never execute
            // so they never fire any event. $stepIndex is the branch group's
            // outer index.
            PipelineEventDispatcher::fireStepCompleted($manifest, $groupIndex, $selectedClass);

            $manifest->failureException = null;
            $manifest->failedStepClass = null;
            $manifest->failedStepIndex = null;

            if ($advanceOuter) {
                $manifest->advanceStep();
            }
        } catch (Throwable $innerException) {
            $cause = $innerException instanceof StepExecutionFailed
                ? ($innerException->getPrevious() ?? $innerException)
                : $innerException;

            $manifest->failureException = $cause;
            $manifest->failedStepClass = $selectedClass;
            $manifest->failedStepIndex = $groupIndex;

            // Story 9.1 AC #12: PipelineStepFailed on the selected branch's
            // inner step; $stepClass is the concrete step class.
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
                Log::warning('Pipeline conditional-branch inner step skipped under SkipAndContinue', [
                    'pipelineId' => $manifest->pipelineId,
                    'groupIndex' => $groupIndex,
                    'selectedKey' => $selectedKey,
                    'selectedClass' => $selectedClass,
                    'nestedName' => $nestedName,
                    'exception' => $cause->getMessage(),
                ]);

                $manifest->failureException = null;

                if ($advanceOuter) {
                    $manifest->advanceStep();
                }

                return;
            }

            if ($manifest->failStrategy === FailStrategy::StopAndCompensate) {
                try {
                    SyncCompensationRunner::run($manifest);
                } catch (Throwable $compensationException) {
                    Log::error('Pipeline compensation chain failed during conditional-branch rollback', [
                        'pipelineId' => $manifest->pipelineId,
                        'groupIndex' => $groupIndex,
                        'selectedKey' => $selectedKey,
                        'selectedClass' => $selectedClass,
                        'nestedName' => $nestedName,
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

            // Story 9.1 AC #8: PipelineCompleted fires at the terminal failure
            // exit of the selected branch's inner step.
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
     * Apply FailStrategy branching to a conditional-branch selector failure.
     *
     * Selector failures (thrown closure, non-string return, unknown branch
     * key) route through this helper so the outer pipeline's FailStrategy is
     * honored uniformly: SkipAndContinue advances past the branch group,
     * StopAndCompensate runs the compensation chain before rethrowing, and
     * StopImmediately rethrows directly. Pipeline-level onFailure and
     * onComplete callbacks fire before the rethrow (StopImmediately +
     * StopAndCompensate only).
     *
     * No onStepFailed hook fires because the selector is infrastructure, not
     * a user step; no StepDefinition is associated with the failure.
     */
    private static function handleSelectorFailure(
        PipelineManifest $manifest,
        int $groupIndex,
        string $branchWrapperLabel,
        Throwable $cause,
        ?string $nestedName,
        bool $advanceOuter,
    ): void {
        $manifest->failureException = $cause;
        $manifest->failedStepClass = $branchWrapperLabel;
        $manifest->failedStepIndex = $groupIndex;

        // Story 9.1 AC #12: selector failure (throw, non-string return,
        // unknown key) fires PipelineStepFailed with the ConditionalBranch<>
        // label; no onStepFailed hook runs for selector failures because the
        // selector is infrastructure, not a user step.
        PipelineEventDispatcher::fireStepFailed($manifest, $groupIndex, $branchWrapperLabel, $cause);

        if ($manifest->failStrategy === FailStrategy::SkipAndContinue) {
            Log::warning('Pipeline conditional-branch selector failed under SkipAndContinue', [
                'pipelineId' => $manifest->pipelineId,
                'groupIndex' => $groupIndex,
                'branchLabel' => $branchWrapperLabel,
                'nestedName' => $nestedName,
                'exception' => $cause->getMessage(),
            ]);

            $manifest->failureException = null;

            if ($advanceOuter) {
                $manifest->advanceStep();
            }

            return;
        }

        if ($manifest->failStrategy === FailStrategy::StopAndCompensate) {
            try {
                SyncCompensationRunner::run($manifest);
            } catch (Throwable $compensationException) {
                Log::error('Pipeline compensation chain failed during conditional-branch selector rollback', [
                    'pipelineId' => $manifest->pipelineId,
                    'groupIndex' => $groupIndex,
                    'branchLabel' => $branchWrapperLabel,
                    'nestedName' => $nestedName,
                    'compensationException' => $compensationException->getMessage(),
                    'originalException' => $cause->getMessage(),
                ]);
            }
        }

        try {
            StepInvoker::firePipelineCallback($manifest->onFailureCallback, $manifest->context, $cause);
        } catch (Throwable $callbackException) {
            throw StepExecutionFailed::forCallbackFailure(
                $manifest->pipelineId,
                $groupIndex,
                $branchWrapperLabel,
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
                $branchWrapperLabel,
                $callbackException,
                $cause,
            );
        }

        // Story 9.1 AC #8: PipelineCompleted fires at the terminal failure
        // exit of a selector failure under StopImmediately / StopAndCompensate.
        PipelineEventDispatcher::fireCompleted($manifest);

        throw StepExecutionFailed::forStep(
            $manifest->pipelineId,
            $groupIndex,
            $branchWrapperLabel,
            $cause,
        );
    }
}
