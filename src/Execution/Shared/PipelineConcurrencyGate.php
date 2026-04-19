<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Shared;

use Illuminate\Support\Facades\Cache;
use Vherbaut\LaravelPipelineJobs\ConcurrencyPolicy;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineConcurrencyLimitExceeded;

/**
 * Internal helper that uses the Cache facade as an atomic counter to gate
 * the number of concurrent pipeline run() invocations sharing the same key.
 *
 * Three public static entrypoints: acquire() (admission + counter increment),
 * release() (terminal decrement), cacheKey() (key namespacing exposed for
 * test assertions).
 *
 * Atomicity caveat: relies on Cache::increment() / Cache::decrement() being
 * atomic, which holds on Redis, Memcached, and Database stores. The file
 * and array stores are NOT atomic across workers; production users MUST
 * configure Redis/Memcached/Database. This is documented on
 * PipelineBuilder::maxConcurrent() PHPDoc.
 */
final class PipelineConcurrencyGate
{
    /**
     * Cache-key namespace prefix applied to every resolved concurrency key.
     */
    private const KEY_PREFIX = 'pipeline:concurrent:';

    /**
     * Acquire a concurrency slot for the resolved key.
     *
     * Behavior:
     *
     * - $policy === null returns null (zero-overhead fast path; no facade
     *   resolution, no key resolution, nothing to release later).
     * - Otherwise resolves the key, seeds the counter with Cache::add()
     *   (no-op when key already exists), atomically increments via
     *   Cache::increment(), and either returns the namespaced cache key on
     *   admission OR decrements + throws PipelineConcurrencyLimitExceeded
     *   when the post-increment count exceeds the policy limit.
     *
     * The TTL on the seeded counter is max(3600, $limit * 60) seconds: a
     * safety net that reclaims the slot if a crashed worker never reaches
     * release(). Under normal operation every acquire has a matching
     * release via try/finally semantics, so the TTL is rarely exercised.
     *
     * @param ConcurrencyPolicy|null $policy The pipeline's concurrency policy, or null when not configured.
     * @param PipelineContext|null $context The resolved pipeline context at admission time, or null.
     *
     * @return string|null The namespaced cache key on success (pass to release() at terminal exit), or null when no policy.
     *
     * @throws PipelineConcurrencyLimitExceeded When the configured limit for the resolved key is reached.
     */
    public static function acquire(?ConcurrencyPolicy $policy, ?PipelineContext $context): ?string
    {
        if ($policy === null) {
            return null;
        }

        $resolvedKey = $policy->resolveKey($context);
        $cacheKey = self::cacheKey($resolvedKey);

        Cache::add($cacheKey, 0, max(3600, $policy->limit * 60));

        $current = Cache::increment($cacheKey);

        if ($current > $policy->limit) {
            Cache::decrement($cacheKey);

            throw PipelineConcurrencyLimitExceeded::forKey($resolvedKey, $policy->limit);
        }

        return $cacheKey;
    }

    /**
     * Release a previously acquired concurrency slot.
     *
     * Behavior:
     *
     * - $key === null is a no-op (covers the zero-overhead fast path where
     *   acquire() returned null because no policy was configured).
     * - Otherwise atomically decrements the counter via Cache::decrement().
     *   If the underlying key has expired (TTL elapsed after a worker
     *   crash), Cache::decrement() returns false on most stores; the
     *   helper tolerates this silently because the slot has already been
     *   reclaimed by the TTL.
     *
     * @param string|null $key The namespaced cache key returned by acquire(), or null.
     *
     * @return void
     */
    public static function release(?string $key): void
    {
        if ($key === null) {
            return;
        }

        Cache::decrement($key);
    }

    /**
     * Build the namespaced cache key for a resolved concurrency key.
     *
     * Exposed for test assertions and for symmetric inspection from
     * acquire(); never call Cache::* with the bare resolved key.
     *
     * @param string $resolvedKey The post-resolution string returned by ConcurrencyPolicy::resolveKey().
     *
     * @return string The namespaced cache key (e.g., "pipeline:concurrent:tenant:42").
     */
    public static function cacheKey(string $resolvedKey): string
    {
        return self::KEY_PREFIX.$resolvedKey;
    }
}
