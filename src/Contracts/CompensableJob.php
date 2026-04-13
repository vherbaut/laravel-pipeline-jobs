<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Contracts;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

/**
 * Saga compensation contract implemented by jobs that know how to undo the
 * side effects produced by a previously successful pipeline step.
 *
 * Classes implementing this interface declare their rollback logic via the
 * compensate() method. The executor (wired in Story 5.2) calls compensate()
 * with the PipelineContext present at the failure point, in reverse order of
 * the successfully executed steps, whenever the pipeline's FailStrategy is
 * StopAndCompensate.
 *
 * Implementing this interface is orthogonal to the InteractsWithPipeline
 * trait: both patterns coexist. The interface is the explicit, signature-level
 * contract; the trait is an opt-in helper for context access inside handle().
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
     * @param PipelineContext $context The pipeline context present at the failure point.
     * @return void
     */
    public function compensate(PipelineContext $context): void;
}
