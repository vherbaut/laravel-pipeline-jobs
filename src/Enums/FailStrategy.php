<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Enums;

/**
 * Saga failure strategy applied when a pipeline step fails.
 *
 * Stored on the immutable PipelineDefinition via PipelineBuilder::onFailure()
 * and read by executors to decide how to react to a step failure. The enum is
 * pure (non-backed): cases are compared by identity (===) and the set is fixed.
 *
 * Behaviour:
 * - StopAndCompensate: halt pipeline execution and run compensation jobs in
 *   reverse order of the successful steps. Compensation execution is wired
 *   in Story 5.2; in Story 5.1 the strategy is set but not yet consumed.
 * - SkipAndContinue: log the failure, skip the failed step, and continue with
 *   the next step using the context from the last successful step. SkipAndContinue
 *   semantics are wired in Story 5.3; in Story 5.1 the strategy is set but not
 *   yet consumed.
 * - StopImmediately: halt pipeline execution without running any compensation.
 *   This is the Epic 1 default (FR28) and is applied when onFailure() was never
 *   called on the builder.
 */
enum FailStrategy
{
    case StopAndCompensate;

    case SkipAndContinue;

    case StopImmediately;
}
