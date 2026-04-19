<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Queued;

use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\PipelineEventDispatcher;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvoker;

/**
 * Queued handler for a ConditionalBranch outer position.
 *
 * Evaluates the selector closure against the live manifest context to resolve
 * the selected branch key, looks up the matching branch value, and rewrites
 * the outer manifest slot at `$groupIndex` with the resolved value (flat
 * class-string or nested-pipeline shape) via
 * {@see PipelineManifest::withRebrandedStepEntryAtCursor()}. Dispatches a
 * fresh {@see PipelineStepJob} wrapper on the rebranded manifest; the next
 * worker's `handle()` sees a plain flat-class or nested-shape at the outer
 * position and routes through the existing flat / nested code path.
 *
 * The selector runs EXACTLY ONCE per branch traversal: after the rebrand,
 * downstream wrappers see a non-branch shape and never re-evaluate the
 * selector. This guarantees deterministic behavior for selectors with side
 * effects.
 *
 * Selector failures (thrown, non-string return, unknown key) propagate as
 * StepExecutionFailed to the caller's try/catch, where the standard
 * FailStrategy branching applies uniformly across branch and single-step
 * failures.
 *
 * @internal
 */
final class QueuedConditionalBranchHandler
{
    /**
     * Handle a ConditionalBranch outer position by evaluating the selector, rebranding the manifest, and dispatching.
     *
     * For a nested-pipeline branch value, the nested cursor is initialized to
     * `[$groupIndex, 0]` on the rebranded manifest BEFORE dispatch so the next
     * wrapper resolves the first inner step via `stepClassAt($cursor)`.
     *
     * @param PipelineManifest $manifest The live manifest carrying the selector's context and the branch envelope.
     * @param int $groupIndex The outer position of the branch group in the pipeline.
     * @param array<string, mixed> $branchShape The resolved branch shape at that position (`['type' => 'branch', 'name' => ?, 'selector' => SerializableClosure, 'branches' => [...]]`).
     * @return void
     *
     * @throws Throwable When the selector throws, returns a non-string, or returns an unknown key (wrapped as StepExecutionFailed for observability).
     */
    public static function handle(PipelineManifest $manifest, int $groupIndex, array $branchShape): void
    {
        $nestedName = $branchShape['name'] ?? null;
        $branchWrapperLabel = $nestedName !== null ? "ConditionalBranch<{$nestedName}>" : 'ConditionalBranch';

        /** @var SerializableClosure $serializableSelector */
        $serializableSelector = $branchShape['selector'];

        try {
            $selectorClosure = $serializableSelector->getClosure();
            $selectedKey = $selectorClosure($manifest->context);
        } catch (Throwable $selectorException) {
            self::handleSelectorFailure($manifest, $groupIndex, $branchWrapperLabel, $selectorException);

            return;
        }

        if (! is_string($selectedKey)) {
            self::handleSelectorFailure(
                $manifest,
                $groupIndex,
                $branchWrapperLabel,
                InvalidPipelineDefinition::branchSelectorMustReturnString(get_debug_type($selectedKey)),
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
            );

            return;
        }

        $selectedValue = $branches[$selectedKey];

        // Determine the cursor path where the branch lives. For a top-level
        // branch, cursor is empty and the branch lives at stepClasses[$groupIndex];
        // for a branch-inside-nested, the cursor already points at the branch
        // position inside the nested envelope.
        $cursorPath = $manifest->nestedCursor !== [] ? $manifest->nestedCursor : [$groupIndex];

        // Navigate to the branch envelope in stepConfigs / stepConditions so we
        // can resolve the config and condition entries for the SELECTED branch
        // key. stepConfigAt() returns the branch envelope verbatim when the
        // cursor lands on a branch position.
        $branchConfigsEnvelope = $manifest->stepConfigAt($cursorPath);
        $branchConditionsEnvelope = self::navigateConditionsEnvelope($manifest->stepConditions, $cursorPath);

        /** @var array<string, array<string, mixed>|null> $allConditions */
        $allConditions = (is_array($branchConditionsEnvelope) && ($branchConditionsEnvelope['type'] ?? null) === 'branch')
            ? $branchConditionsEnvelope['entries']
            : [];
        $resolvedCondition = $allConditions[$selectedKey] ?? null;

        /** @var array<string, array<string, mixed>> $allConfigs */
        $allConfigs = (($branchConfigsEnvelope['type'] ?? null) === 'branch')
            ? $branchConfigsEnvelope['configs']
            : [];
        $resolvedConfig = $allConfigs[$selectedKey] ?? [];

        $rebranded = $manifest->withRebrandedStepEntryAtCursor(
            $cursorPath,
            $selectedValue,
            $resolvedConfig,
            $resolvedCondition,
        );

        if (is_array($selectedValue) && ($selectedValue['type'] ?? null) === 'nested') {
            // Extend the cursor one level deeper to point at the first inner
            // step of the rebranded nested shape.
            $rebranded->nestedCursor = [...$cursorPath, 0];
        }

        PipelineStepJob::dispatchWrapperFor($rebranded);
    }

    /**
     * Navigate the stepConditions tree to the given cursor path and return the envelope at that position.
     *
     * Mirrors {@see PipelineManifest::stepConfigAt()} navigation but returns
     * the discriminator-tagged envelope at the cursor target instead of
     * filtering it out. Returns null when the path cannot be navigated
     * (legacy payload, out-of-range segment, or intermediate envelope
     * missing).
     *
     * @param array<int, mixed> $stepConditions The manifest's stepConditions tree.
     * @param array<int, int> $cursorPath Non-empty cursor path to navigate.
     * @return array<string, mixed>|null The envelope at the cursor path, or null when not resolvable.
     */
    private static function navigateConditionsEnvelope(array $stepConditions, array $cursorPath): ?array
    {
        $outer = $cursorPath[0];

        if (! array_key_exists($outer, $stepConditions) || ! is_array($stepConditions[$outer])) {
            return null;
        }

        $current = $stepConditions[$outer];
        $pathLength = count($cursorPath);

        for ($depth = 1; $depth < $pathLength; $depth++) {
            if (! is_array($current)) {
                return null;
            }

            $type = $current['type'] ?? null;

            if ($type !== 'nested' && $type !== 'parallel') {
                return null;
            }

            /** @var array<int, array<string, mixed>|null> $innerEntries */
            $innerEntries = $current['entries'] ?? [];
            $segment = $cursorPath[$depth];

            if (! array_key_exists($segment, $innerEntries)) {
                return null;
            }

            $current = $innerEntries[$segment];
        }

        return is_array($current) ? $current : null;
    }

    /**
     * Apply FailStrategy branching to a queued conditional-branch selector failure.
     *
     * Selector failures route through this helper so the outer pipeline's
     * FailStrategy is honored uniformly:
     *
     * - SkipAndContinue: advances past the branch group and dispatches the
     *   next wrapper (or fires the success tail when the pipeline is complete).
     * - StopAndCompensate: clears the nested cursor and dispatches the
     *   compensation chain; pipeline-level onFailure + onComplete fire before
     *   the terminal rethrow.
     * - StopImmediately: rethrows directly; pipeline-level onFailure +
     *   onComplete fire before the rethrow.
     *
     * No `onStepFailed` hook fires because the selector is infrastructure,
     * not a user step; no StepDefinition is associated with the failure.
     */
    private static function handleSelectorFailure(
        PipelineManifest $manifest,
        int $groupIndex,
        string $branchWrapperLabel,
        Throwable $cause,
    ): void {
        $manifest->failureException = $cause;
        $manifest->failedStepClass = $branchWrapperLabel;
        $manifest->failedStepIndex = $groupIndex;

        // Story 9.1 AC #12: queued-mode selector failure fires PipelineStepFailed
        // with the ConditionalBranch<> label under ALL FailStrategy branches.
        PipelineEventDispatcher::fireStepFailed($manifest, $groupIndex, $branchWrapperLabel, $cause);

        if ($manifest->failStrategy === FailStrategy::SkipAndContinue) {
            Log::warning('Pipeline conditional-branch selector failed under SkipAndContinue', [
                'pipelineId' => $manifest->pipelineId,
                'groupIndex' => $groupIndex,
                'branchLabel' => $branchWrapperLabel,
                'nestedCursor' => $manifest->nestedCursor,
                'exception' => $cause->getMessage(),
            ]);

            $manifest->failureException = null;

            try {
                PipelineStepJob::advanceCursorOrOuter($manifest);

                if (PipelineStepJob::hasMorePositions($manifest)) {
                    PipelineStepJob::dispatchWrapperFor($manifest);
                } else {
                    StepInvoker::firePipelineCallback($manifest->onSuccessCallback, $manifest->context);
                    StepInvoker::firePipelineCallback($manifest->onCompleteCallback, $manifest->context);

                    // Story 9.1 AC #8: PipelineCompleted fires at the terminal
                    // success tail when a SkipAndContinue selector failure
                    // completes the pipeline with no more positions to dispatch.
                    PipelineEventDispatcher::fireCompleted($manifest);
                }
            } catch (Throwable $dispatchException) {
                Log::error('Pipeline next-step dispatch failed under SkipAndContinue after selector failure', [
                    'pipelineId' => $manifest->pipelineId,
                    'groupIndex' => $groupIndex,
                    'branchLabel' => $branchWrapperLabel,
                    'exception' => $dispatchException->getMessage(),
                ]);

                throw $dispatchException;
            }

            return;
        }

        if ($manifest->failStrategy === FailStrategy::StopAndCompensate) {
            $manifest->nestedCursor = [];
            QueuedCompensationDispatcher::dispatchChain($manifest);
        }

        Log::error('Pipeline conditional-branch selector failed', [
            'pipelineId' => $manifest->pipelineId,
            'currentStepIndex' => $groupIndex,
            'branchLabel' => $branchWrapperLabel,
            'exception' => $cause->getMessage(),
        ]);

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
        // exit of a queued selector failure AFTER onFailure + onComplete
        // callbacks and BEFORE the StepExecutionFailed rethrow.
        PipelineEventDispatcher::fireCompleted($manifest);

        throw StepExecutionFailed::forStep(
            $manifest->pipelineId,
            $groupIndex,
            $branchWrapperLabel,
            $cause,
        );
    }
}
