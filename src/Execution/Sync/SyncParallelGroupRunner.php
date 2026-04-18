<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Sync;

use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepConditionEvaluator;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvoker;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * Synchronous runner for a declared parallel step group.
 *
 * Synchronous parallelism is semantic, not concurrent: each sub-step runs
 * inline in declaration order and shares the SAME live PipelineContext with
 * its siblings, so mutations by earlier sub-steps are visible to later ones
 * within the group (users expecting isolation must queue the pipeline).
 * Each sub-step contributes to the flat `$manifest->completedSteps` list by
 * its own class name so reverse-order compensation over a StopAndCompensate
 * failure includes it. The outer position advances exactly ONCE after all
 * sub-steps have been processed.
 *
 * Per-sub-step `when()`/`unless()` conditions are evaluated via
 * {@see StepConditionEvaluator::shouldSkipEntry()}; skipped sub-steps do NOT
 * fire before/afterEach, do NOT record completion, and do NOT clear
 * SkipAndContinue failure fields.
 *
 * Per-sub-step queue/connection/timeout config is INERT in sync mode (parity
 * with the single-step sync path). Per-sub-step retry/backoff runs via
 * {@see StepInvoker::invokeWithRetry()}, identical to the single-step site.
 *
 * @internal
 */
final class SyncParallelGroupRunner
{
    /**
     * Execute a parallel group identified by its outer position.
     *
     * Resolves the per-sub-step condition and config arrays from the manifest
     * envelope, delegates to {@see self::executeEntries()} without advancing,
     * then advances the outer position exactly once at the terminal.
     *
     * @param PipelineManifest $manifest The mutable manifest carrying context, completedSteps, and per-step conditions/configs.
     * @param int $groupIndex The outer position of the parallel group in the pipeline.
     * @param array<int, string> $subStepClasses Sub-step class-strings in declaration order.
     * @return void
     *
     * @throws StepExecutionFailed When a sub-step fails under StopImmediately or StopAndCompensate (or when a hook or callback re-throws).
     */
    public static function executeGroup(PipelineManifest $manifest, int $groupIndex, array $subStepClasses): void
    {
        $groupConditions = $manifest->stepConditions[$groupIndex] ?? null;
        $groupConfigs = $manifest->stepConfigs[$groupIndex] ?? null;

        /** @var array<int, array{closure: SerializableClosure, negated: bool}|null> $subConditions */
        $subConditions = (is_array($groupConditions) && ($groupConditions['type'] ?? null) === 'parallel')
            ? $groupConditions['entries']
            : [];

        /** @var array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}> $subConfigs */
        $subConfigs = (is_array($groupConfigs) && ($groupConfigs['type'] ?? null) === 'parallel')
            ? $groupConfigs['configs']
            : [];

        self::executeEntries($manifest, $groupIndex, $subStepClasses, $subConditions, $subConfigs);

        $manifest->advanceStep();
    }

    /**
     * Execute a list of parallel sub-steps in the current process without advancing the outer position.
     *
     * Extracted from {@see self::executeGroup()} so the nested-pipeline path
     * ({@see SyncNestedPipelineRunner}) can reuse the parallel sub-step body
     * for a parallel-inside-nested entry without double-advancing the outer
     * `currentStepIndex` (the nested group advances once at its own terminal).
     * Callers invoked from the outer execute() loop wrap this helper with
     * `$manifest->advanceStep();` callers invoked from inside a nested group
     * do NOT advance, letting the enclosing nested group's single advance at
     * its terminal govern.
     *
     * @param PipelineManifest $manifest The mutable manifest carrying context, completedSteps, and hooks.
     * @param int $groupIndex The outer position of the enclosing group (used for observability on failure).
     * @param array<int, string> $subStepClasses Sub-step class-strings in declaration order.
     * @param array<int, array{closure: SerializableClosure, negated: bool}|null> $subConditions Per-sub-step condition entries aligned with $subStepClasses; null means unconditional.
     * @param array<int, array{queue: ?string, connection: ?string, sync: bool, retry: ?int, backoff: ?int, timeout: ?int}> $subConfigs Per-sub-step resolved configs aligned with $subStepClasses.
     * @return void
     *
     * @throws StepExecutionFailed When a sub-step fails under StopImmediately or StopAndCompensate (or when a hook or callback re-throws).
     */
    public static function executeEntries(
        PipelineManifest $manifest,
        int $groupIndex,
        array $subStepClasses,
        array $subConditions,
        array $subConfigs,
    ): void {
        $defaultConfig = ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null];

        foreach ($subStepClasses as $subIndex => $subStepClass) {
            // Evaluate the sub-step condition OUTSIDE the outer try so a
            // throwing condition closure is not misattributed to the
            // sub-step's handle() via the sub-step catch block (the sub-step
            // never ran). Condition-throws still surface as
            // StepExecutionFailed::forStep with the sub-step class as the
            // location so operators get a clear trail.
            try {
                $shouldSkip = StepConditionEvaluator::shouldSkipEntry($subConditions[$subIndex] ?? null, $manifest->context);
            } catch (Throwable $conditionException) {
                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $groupIndex,
                    $subStepClass,
                    $conditionException,
                );
            }

            if ($shouldSkip) {
                continue;
            }

            try {
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

                StepInvoker::invokeWithRetry($job, $subConfigs[$subIndex] ?? $defaultConfig);

                StepInvoker::fireHooks(
                    $manifest->afterEachHooks,
                    StepDefinition::fromJobClass($subStepClass),
                    $manifest->context,
                );

                $manifest->markStepCompleted($subStepClass);

                // Under SkipAndContinue, a sub-step success clears any failure
                // recorded by an earlier sub-step in the same group (AC #9).
                $manifest->failureException = null;
                $manifest->failedStepClass = null;
                $manifest->failedStepIndex = null;
            } catch (Throwable $subException) {
                $manifest->failureException = $subException;
                $manifest->failedStepClass = $subStepClass;
                $manifest->failedStepIndex = $groupIndex;

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

                if ($manifest->failStrategy === FailStrategy::StopAndCompensate) {
                    // Wrap the compensation chain so a throwing compensation
                    // does NOT skip over the onFailure / onComplete sequence
                    // below. The original sub-step exception stays the
                    // canonical cause; the compensation failure is logged
                    // for operator diagnostics.
                    try {
                        SyncCompensationRunner::run($manifest);
                    } catch (Throwable $compensationException) {
                        Log::error('Pipeline compensation chain failed during parallel group rollback', [
                            'pipelineId' => $manifest->pipelineId,
                            'groupIndex' => $groupIndex,
                            'subStepClass' => $subStepClass,
                            'compensationException' => $compensationException->getMessage(),
                            'originalException' => $subException->getMessage(),
                        ]);
                    }
                }

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

                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $manifest->currentStepIndex,
                    $subStepClass,
                    $subException,
                );
            }
        }
    }
}
