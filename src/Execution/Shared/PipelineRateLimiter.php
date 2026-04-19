<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Shared;

use Illuminate\Support\Facades\RateLimiter;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineThrottled;
use Vherbaut\LaravelPipelineJobs\RateLimitPolicy;

/**
 * Internal helper that consults Laravel's RateLimiter facade to gate a
 * pipeline run() at admission time.
 *
 * Single public static entrypoint gate(): null-guard fast path when the
 * pipeline did not configure rateLimit(); resolve the user-supplied key
 * (string or closure); reject via PipelineThrottled when the quota is
 * exhausted; consume one token via RateLimiter::hit() on admission.
 *
 * The helper does NOT swallow RateLimiter facade errors (driver
 * misconfiguration must surface, not be hidden).
 */
final class PipelineRateLimiter
{
    /**
     * Evaluate the rate-limit gate for one admission attempt.
     *
     * Behavior:
     *
     * - $policy === null returns immediately (zero-overhead fast path; no
     *   facade resolution, no key resolution).
     * - Otherwise resolves the policy's key against the live context, asks
     *   RateLimiter::tooManyAttempts() whether the quota is exhausted, and
     *   throws PipelineThrottled with a populated retry-after value when
     *   the answer is yes.
     * - On admission, consumes a token via RateLimiter::hit() with the
     *   policy's window length so the next call observes the consumption.
     *
     * @param RateLimitPolicy|null $policy The pipeline's rate-limit policy, or null when not configured.
     * @param PipelineContext|null $context The resolved pipeline context at admission time, or null.
     *
     * @return void
     *
     * @throws PipelineThrottled When the rate-limit quota for the resolved key is exhausted.
     */
    public static function gate(?RateLimitPolicy $policy, ?PipelineContext $context): void
    {
        if ($policy === null) {
            return;
        }

        $key = $policy->resolveKey($context);

        if (RateLimiter::tooManyAttempts($key, $policy->max)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw PipelineThrottled::forKey($key, $retryAfter, $policy->max, $policy->perSeconds);
        }

        RateLimiter::hit($key, $policy->perSeconds);
    }
}
