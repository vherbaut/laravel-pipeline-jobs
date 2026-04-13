<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ReflectionProperty;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;

/**
 * Internal queued wrapper that invokes a single compensation job on the queue.
 *
 * Not part of the public API. Carries the full PipelineManifest plus the
 * compensation class name in its serialized payload. Produced by the queued
 * compensation dispatch path (PipelineStepJob::dispatchCompensationChain())
 * as a member of a Bus::chain() so each compensation runs on its own worker
 * in reverse order of the successfully completed steps.
 *
 * Compensation classes are not required to implement ShouldQueue themselves:
 * the wrapper applies the CompensableJob-or-trait bridge inside handle() and
 * invokes the underlying compensation logic via the container, mirroring the
 * sync bridge in SyncExecutor::runCompensationChain() and the recording
 * bridge in RecordingExecutor::runCompensation().
 *
 * Known semantic divergence vs. sync: Bus::chain() halts as soon as a
 * CompensationStepJob throws, so remaining compensations in the reversed
 * chain are not executed (each wrapper also has tries = 1). Sync compensation
 * (SyncExecutor::runCompensationChain) swallows per-compensation exceptions
 * and continues. Unification is deferred to Story 5.3 when CompensationFailed
 * events and logging land (NFR6); operators should not rely on best-effort
 * rollback in queued mode today.
 *
 * @internal
 */
final class CompensationStepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts for this compensation wrapper job.
     *
     * Each compensation invocation is best-effort; retry policy is controlled
     * by the queue worker configuration. The wrapper defaults to 1 attempt so
     * a crash between compensation success and the chain continuation does
     * not re-execute an already-rolled-back step.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Create a new compensation step wrapper.
     *
     * @param string $compensationClass Fully qualified class name of the compensation job to invoke.
     * @param PipelineManifest $manifest Mutable manifest carrying context, completed steps, compensation mapping, and failure strategy.
     * @return void
     */
    public function __construct(
        public readonly string $compensationClass,
        public PipelineManifest $manifest,
    ) {}

    /**
     * Invoke the compensation class via the CompensableJob-or-trait bridge.
     *
     * Resolves the compensation class from the container, then:
     * - if the instance implements CompensableJob, calls compensate($context)
     *   with the manifest's current PipelineContext (Story 5.1 contract);
     * - otherwise, injects the manifest via ReflectionProperty into the
     *   instance's pipelineManifest property when present, then invokes
     *   handle() through the container so DI works for trait-based
     *   compensation jobs (Story 3.3 pattern).
     *
     * Exceptions propagate to Laravel's native queue failure handling so the
     * job is recorded in failed_jobs. The wrapper caps $tries to 1 (see
     * property PHPDoc) so a single throw also halts the surrounding
     * Bus::chain() and the remaining compensations are skipped (NFR5,
     * documented divergence vs. sync best-effort — see class PHPDoc).
     *
     * @return void
     */
    public function handle(): void
    {
        $job = app()->make($this->compensationClass);

        if ($job instanceof CompensableJob) {
            $job->compensate($this->manifest->context);

            return;
        }

        if (property_exists($job, 'pipelineManifest')) {
            $property = new ReflectionProperty($job, 'pipelineManifest');
            $property->setValue($job, $this->manifest);
        }

        app()->call([$job, 'handle']);
    }
}
