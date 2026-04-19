<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Sync;

use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\CompensationInvoker;

/**
 * Synchronous best-effort compensation chain runner.
 *
 * Only invoked when `$manifest->failStrategy === FailStrategy::StopAndCompensate`.
 * Reads the ordered list of completed steps from `$manifest->completedSteps`,
 * reverses it, and for each completed step whose class has a compensation
 * mapping in `$manifest->compensationMapping`, resolves the compensation via
 * the container and invokes it through the CompensableJob-or-trait bridge:
 *
 * - If the compensation implements CompensableJob, calls `compensate($context)`
 *   (via {@see CompensationInvoker::invokeCompensate()}).
 * - Otherwise, injects the manifest into a `pipelineManifest` property when
 *   present, then calls `handle()` via the container (legacy pattern).
 *
 * Compensation is best-effort: a throwable from one compensation is logged
 * and event-dispatched via {@see CompensationInvoker::reportCompensationFailure()},
 * then the chain continues with the next entry.
 *
 * @internal
 */
final class SyncCompensationRunner
{
    /**
     * Run compensation jobs for every completed step in reverse order.
     *
     * @param PipelineManifest $manifest The manifest carrying completedSteps, compensationMapping, and context.
     * @return void
     */
    public static function run(PipelineManifest $manifest): void
    {
        if ($manifest->compensationMapping === []) {
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

                    continue;
                }

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                app()->call([$job, 'handle']);
            } catch (Throwable $compensationException) {
                CompensationInvoker::reportCompensationFailure(
                    $manifest,
                    $compensationClass,
                    $manifest->failureException,
                    $compensationException,
                );
                // Best-effort: compensation failures do not abort the chain.
            }
        }
    }
}
