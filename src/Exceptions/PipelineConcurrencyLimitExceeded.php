<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Exceptions;

use Throwable;

/**
 * Thrown when a pipeline run() invocation is rejected by the concurrency gate.
 *
 * Carries the resolved concurrency key and the configured per-key limit so
 * callers can surface a meaningful response (e.g., back-off, dead-letter
 * routing, alerting). The pipeline body has NOT executed when this is
 * thrown: no step ran, no hook fired, no event dispatched, no callback
 * fired. The concurrency counter is decremented before the throw so the
 * rejected attempt does NOT consume a slot.
 */
class PipelineConcurrencyLimitExceeded extends PipelineException
{
    /**
     * Construct the exception with the resolved key and configured limit.
     *
     * @param string $message The formatted error message describing the rejection.
     * @param string $key The resolved concurrency key (post-closure resolution).
     * @param int $limit The configured per-key concurrency limit (echoed from the policy).
     * @param Throwable|null $previous Optional previous exception to chain.
     */
    public function __construct(
        string $message,
        public readonly string $key,
        public readonly int $limit,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Build a PipelineConcurrencyLimitExceeded exception from the resolved
     * key + limit.
     *
     * Standard factory used by PipelineConcurrencyGate::acquire() when the
     * post-increment counter exceeds the policy limit.
     *
     * @param string $key The resolved concurrency key.
     * @param int $limit The configured per-key concurrency limit.
     *
     * @return self
     */
    public static function forKey(string $key, int $limit): self
    {
        return new self(
            message: sprintf(
                'Pipeline concurrency limit exceeded for key "%s" (limit: %d).',
                $key,
                $limit,
            ),
            key: $key,
            limit: $limit,
        );
    }
}
