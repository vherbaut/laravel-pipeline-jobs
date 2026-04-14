<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;

/**
 * Internal queued wrapper that executes a single pipeline step and chains the next.
 *
 * Not part of the public API. Carries the full PipelineManifest in its
 * serialized payload so the executor can resume from any step on any
 * worker. After handling the current step successfully, the job mutates
 * the manifest (markStepCompleted + advanceStep) and self-dispatches the
 * next PipelineStepJob until the pipeline is complete. Failures are logged
 * with pipeline context and rethrown so Laravel's native queue failure
 * handling (failed_jobs, retry, failed()) fires for the wrapper job.
 *
 * When the manifest's failStrategy is StopAndCompensate, the wrapper job
 * also dispatches a Bus::chain() of CompensationStepJob instances in
 * reverse order of the completed steps before rethrowing, so each
 * compensation runs on a fresh worker with standard Laravel retry.
 *
 * @internal
 */
final class PipelineStepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts for this wrapper job.
     *
     * Locked to 1 so a worker crash between a successful step and the recursive
     * dispatch cannot cause the already-succeeded step to re-execute under
     * Laravel's default retry policy.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Create a new pipeline step job.
     *
     * @param PipelineManifest $manifest Mutable manifest tracking steps, index, completed list, and context.
     * @return void
     */
    public function __construct(public PipelineManifest $manifest) {}

    /**
     * Execute the current step and dispatch the next wrapper job if any.
     *
     * Resolves the step class referenced by the manifest's currentStepIndex,
     * injects the manifest into the step via ReflectionProperty when the
     * target job exposes a pipelineManifest property, and invokes handle()
     * through the container. On success, advances the manifest and dispatches
     * the next PipelineStepJob. On failure, records failure context on the
     * manifest and branches on failStrategy:
     *
     * - StopImmediately: logs an error and rethrows so Laravel marks the
     *   wrapper failed and halts the chain.
     * - StopAndCompensate: dispatches the reversed compensation chain, logs
     *   an error, then rethrows to halt the chain.
     * - SkipAndContinue: logs a warning, advances past the failed step,
     *   clears the in-process Throwable reference before forward dispatch
     *   (NFR19 belt-and-suspenders), dispatches the next PipelineStepJob
     *   when more steps remain, and returns successfully. The wrapper is
     *   not marked failed. A later successful step clears the recorded
     *   failure fields; a later failure overwrites them.
     *
     * @return void
     * @throws Throwable When the underlying step throws under StopImmediately or StopAndCompensate.
     */
    public function handle(): void
    {
        $stepIndex = $this->manifest->currentStepIndex;

        if (! array_key_exists($stepIndex, $this->manifest->stepClasses)) {
            return;
        }

        $stepClass = $this->manifest->stepClasses[$stepIndex];

        try {
            if ($this->shouldSkip($stepIndex)) {
                $this->manifest->advanceStep();

                if ($this->manifest->currentStepIndex < count($this->manifest->stepClasses)) {
                    dispatch(new self($this->manifest));
                }

                return;
            }

            $job = app()->make($stepClass);

            if (property_exists($job, 'pipelineManifest')) {
                $property = new ReflectionProperty($job, 'pipelineManifest');
                $property->setValue($job, $this->manifest);
            }

            app()->call([$job, 'handle']);
        } catch (Throwable $exception) {
            // Last-failure-wins: subsequent failures overwrite the recorded fields.
            $this->manifest->failureException = $exception;
            $this->manifest->failedStepClass = $stepClass;
            $this->manifest->failedStepIndex = $stepIndex;

            if ($this->manifest->failStrategy === FailStrategy::SkipAndContinue) {
                Log::warning('Pipeline step skipped under SkipAndContinue', [
                    'pipelineId' => $this->manifest->pipelineId,
                    'stepClass' => $stepClass,
                    'stepIndex' => $stepIndex,
                    'exception' => $exception->getMessage(),
                ]);

                $this->manifest->advanceStep();

                // NFR19: clear the non-serializable Throwable before dispatching
                // the next wrapper job so the downstream queue payload stays
                // serializable even outside the structural __serialize guard.
                $this->manifest->failureException = null;

                if ($this->manifest->currentStepIndex < count($this->manifest->stepClasses)) {
                    try {
                        dispatch(new self($this->manifest));
                    } catch (Throwable $dispatchException) {
                        // If dispatch() itself throws (queue driver unavailable,
                        // serialization failure), Laravel's default handling lands
                        // the wrapper in failed_jobs. Log the dispatch-site context
                        // before rethrow so operators can attribute the failure to
                        // the dispatch, not to the already-skipped step.
                        Log::error('Pipeline next-step dispatch failed under SkipAndContinue', [
                            'pipelineId' => $this->manifest->pipelineId,
                            'nextStepIndex' => $this->manifest->currentStepIndex,
                            'skippedStepClass' => $stepClass,
                            'exception' => $dispatchException->getMessage(),
                        ]);

                        throw $dispatchException;
                    }
                }

                return;
            }

            if ($this->manifest->failStrategy === FailStrategy::StopAndCompensate) {
                $this->dispatchCompensationChain();
            }

            Log::error('Pipeline step failed', [
                'pipelineId' => $this->manifest->pipelineId,
                'currentStepIndex' => $stepIndex,
                'stepClass' => $stepClass,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->manifest->markStepCompleted($stepClass);
        $this->manifest->advanceStep();

        // AC #6: a successful step under SkipAndContinue clears any failure
        // recorded by a previously skipped step. No-op under StopImmediately /
        // StopAndCompensate because those paths never reach this success tail
        // with failure fields set.
        $this->manifest->failureException = null;
        $this->manifest->failedStepClass = null;
        $this->manifest->failedStepIndex = null;

        if ($this->manifest->currentStepIndex < count($this->manifest->stepClasses)) {
            dispatch(new self($this->manifest));
        }
    }

    /**
     * Decide whether the step at the given index should be skipped based on its condition entry.
     *
     * Mirrors SyncExecutor::shouldSkipStep(). Returns false when no
     * condition is registered for the index; otherwise unwraps the
     * SerializableClosure and applies the `negated` flag. Called from
     * inside the surrounding try/catch so a throwing closure is logged
     * via Log::error('Pipeline step failed', ...) and rethrown for
     * Laravel's queue failure handling to fire.
     *
     * @param int $stepIndex The zero-based index of the step being evaluated.
     *
     * @return bool True when the step must be skipped, false when it should run.
     */
    private function shouldSkip(int $stepIndex): bool
    {
        $entry = $this->manifest->stepConditions[$stepIndex] ?? null;

        if ($entry === null) {
            return false;
        }

        $closure = $entry['closure']->getClosure();
        $result = (bool) $closure($this->manifest->context);
        $shouldRun = $entry['negated'] ? ! $result : $result;

        return ! $shouldRun;
    }

    /**
     * Dispatch the reversed compensation chain as a Bus::chain of CompensationStepJob.
     *
     * Reverses $this->manifest->completedSteps, looks up the compensation
     * class for each completed step in $this->manifest->compensationMapping,
     * and accumulates a CompensationStepJob wrapper per mapped entry. When
     * the resulting array is non-empty, dispatches Bus::chain($jobs) so each
     * compensation runs on its own worker in reverse order. Short-circuits
     * to no dispatch when no completed step declared a compensation.
     *
     * Called from the failure branch of handle() only when the manifest's
     * failStrategy is FailStrategy::StopAndCompensate.
     *
     * @return void
     */
    private function dispatchCompensationChain(): void
    {
        if ($this->manifest->compensationMapping === []) {
            return;
        }

        $chain = [];
        $reversedCompleted = array_reverse($this->manifest->completedSteps);

        foreach ($reversedCompleted as $completedStep) {
            if (! isset($this->manifest->compensationMapping[$completedStep])) {
                continue;
            }

            $chain[] = new CompensationStepJob(
                $this->manifest->compensationMapping[$completedStep],
                $this->manifest,
            );
        }

        if ($chain === []) {
            return;
        }

        // NFR19: clear the non-serializable throwable from the manifest BEFORE
        // Bus::chain() serializes each CompensationStepJob's payload. The
        // wrapped jobs share the same manifest reference, so nulling here
        // protects every queued compensation payload.
        $this->manifest->failureException = null;

        try {
            Bus::chain($chain)->dispatch();
        } catch (Throwable $dispatchException) {
            // A failure to dispatch the compensation chain (queue driver
            // unavailable, serialization failure) must not mask the original
            // step exception. Log the dispatch failure and let handle()
            // rethrow the real step exception caught upstream.
            Log::error('Pipeline compensation chain dispatch failed', [
                'pipelineId' => $this->manifest->pipelineId,
                'failedStepClass' => $this->manifest->failedStepClass,
                'exception' => $dispatchException->getMessage(),
            ]);
        }
    }
}
