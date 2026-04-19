<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Exceptions;

use Throwable;

/**
 * Thrown when a pipeline run() invocation is rejected by the rate-limit gate.
 *
 * Carries the resolved rate-limit key, the seconds remaining until the next
 * admission window opens, and the configured max / window so callers can
 * surface a meaningful retry hint (e.g., HTTP 429 Retry-After header,
 * back-off scheduling, dead-letter routing). The pipeline body has NOT
 * executed when this is thrown: no step ran, no hook fired, no event
 * dispatched, no callback fired, and the concurrency slot was NOT acquired.
 */
class PipelineThrottled extends PipelineException
{
    /**
     * Construct the exception with the resolved key, retry hint, and policy
     * configuration.
     *
     * @param string $message The formatted error message describing the throttling.
     * @param string $key The resolved rate-limit key (post-closure resolution).
     * @param int $retryAfter Seconds remaining until the next token becomes available.
     * @param int $max Maximum admitted runs per window (echoed from the policy).
     * @param int $perSeconds Window length in seconds (echoed from the policy).
     * @param Throwable|null $previous Optional previous exception to chain.
     */
    public function __construct(
        string $message,
        public readonly string $key,
        public readonly int $retryAfter,
        public readonly int $max,
        public readonly int $perSeconds,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Build a PipelineThrottled exception from the resolved key + retry hint.
     *
     * Standard factory used by PipelineRateLimiter::gate() when
     * RateLimiter::tooManyAttempts() reports the quota is exhausted.
     *
     * @param string $key The resolved rate-limit key.
     * @param int $retryAfter Seconds remaining until the next token becomes available (RateLimiter::availableIn()).
     * @param int $max Maximum admitted runs per window (echoed from the policy).
     * @param int $perSeconds Window length in seconds (echoed from the policy).
     *
     * @return self
     */
    public static function forKey(string $key, int $retryAfter, int $max, int $perSeconds): self
    {
        return new self(
            message: sprintf(
                'Pipeline rate limit exceeded for key "%s" (%d executions per %d seconds); retry after %d seconds.',
                $key,
                $max,
                $perSeconds,
                $retryAfter,
            ),
            key: $key,
            retryAfter: $retryAfter,
            max: $max,
            perSeconds: $perSeconds,
        );
    }
}
