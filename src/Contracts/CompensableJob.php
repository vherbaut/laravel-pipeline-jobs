<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Contracts;

use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

/**
 * Saga compensation contract implemented by jobs that know how to undo the
 * side effects produced by a previously successful pipeline step.
 *
 * Classes implementing this interface declare their rollback logic via the
 * compensate() method. The executor calls compensate()
 * with the PipelineContext present at the failure point, in reverse order of
 * the successfully executed steps, whenever the pipeline's FailStrategy is
 * StopAndCompensate.
 *
 * Implementing this interface is orthogonal to the InteractsWithPipeline
 * trait: both patterns coexist. The interface is the explicit, signature-level
 * contract; the trait is an opt-in helper for context access inside handle().
 *
 * Calling convention: executors inspect the compensate()
 * signature via reflection and pass one or two arguments accordingly.
 * Single-argument implementations keep receiving only the PipelineContext.
 * Two-argument implementations (typed `(PipelineContext $context, ?FailureContext $failure = null)`)
 * additionally receive a populated FailureContext in sync mode and a
 * FailureContext with `$exception === null` in queued mode (Throwables are
 * excluded from queue payloads per NFR19). Signatures requiring more than two
 * parameters are rejected at dispatch time to avoid silent truncation.
 */
interface CompensableJob
{
    /**
     * Undo the side effects produced by the associated pipeline step.
     *
     * Called by the executor when the pipeline fails with a StopAndCompensate
     * strategy. Receives the PipelineContext carried at the failure point, so
     * the compensation logic can inspect the state at the moment of failure.
     *
     * Implementations may widen the signature to accept a second optional
     * FailureContext argument (`?FailureContext $failure = null`) built from
     * the manifest at invocation time. The executor inspects the signature via
     * reflection: single-argument implementations receive only the context,
     * two-argument implementations additionally receive the failure snapshot.
     * The interface itself keeps the single-parameter shape so existing
     * implementations continue to satisfy the contract unchanged; the `$failure`
     * argument is an executor-level extension, not an interface-level tag.
     *
     * @param PipelineContext $context The pipeline context present at the failure point.
     * @return void
     */
    public function compensate(PipelineContext $context): void;
}
