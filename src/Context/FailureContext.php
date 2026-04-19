<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Context;

use Throwable;

/**
 * Read-only snapshot of the failure metadata recorded on a PipelineManifest.
 *
 * Exposed to compensation jobs so rollback logic can inspect which step failed
 * and the exception that caused it. Two consumption paths exist:
 *
 * - CompensableJob implementations receive a FailureContext as the optional
 *   second argument to compensate($context, $failure). The executor builds the
 *   snapshot from the manifest at invocation time.
 * - Trait-based compensation jobs read the snapshot via
 *   InteractsWithPipeline::failureContext().
 *
 * In sync execution, all three properties are populated at invocation time. In
 * queued execution, $exception is null by design: the Throwable is intentionally
 * excluded from serialized queue payloads (NFR19 / PipelineManifest::__serialize).
 */
final readonly class FailureContext
{
    /**
     * Create a new failure-context snapshot.
     *
     * @param string $failedStepClass Fully qualified class name of the step that threw.
     * @param int $failedStepIndex Zero-based index of the failing step within the pipeline's ordered step list.
     * @param Throwable|null $exception Throwable raised by the failing step. Null when built from a queued manifest payload because Throwables are deliberately excluded from serialization (NFR19).
     * @return void
     */
    public function __construct(
        public string $failedStepClass,
        public int $failedStepIndex,
        public ?Throwable $exception,
    ) {}

    /**
     * Build a FailureContext snapshot from a PipelineManifest, or null when no failure has been recorded.
     *
     * Returns null when $manifest->failedStepClass is null (the manifest is in a
     * pristine or post-reset state). Otherwise returns a new snapshot with the
     * failure metadata read from the manifest. The failedStepIndex coalesces to
     * 0 defensively: the compensation-chain invariant guarantees failedStepClass and
     * failedStepIndex are always set together, but PHPStan cannot prove the
     * joint nullability without a conditional type.
     *
     * @param PipelineManifest $manifest The manifest whose failure fields are inspected.
     * @return self|null A new snapshot when the manifest carries a failure, null otherwise.
     */
    public static function fromManifest(PipelineManifest $manifest): ?self
    {
        if ($manifest->failedStepClass === null) {
            return null;
        }

        return new self(
            failedStepClass: $manifest->failedStepClass,
            failedStepIndex: $manifest->failedStepIndex ?? 0,
            exception: $manifest->failureException,
        );
    }
}
