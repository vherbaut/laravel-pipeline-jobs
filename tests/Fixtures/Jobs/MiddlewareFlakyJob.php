<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Closure;
use RuntimeException;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

/**
 * Middleware-shape fixture that fails on the first attempt and succeeds on the second.
 *
 * Used by Story 9.4 retry tests to verify that per-step `retry` config drives
 * the StepInvoker loop for middleware-shape steps identically to handle()-shape
 * steps.
 */
final class MiddlewareFlakyJob
{
    /** @var int Number of attempts seen so far across the pipeline run. */
    public static int $attempts = 0;

    /**
     * Fail on attempt #1, succeed (and mutate) on attempt #2+.
     *
     * @param mixed $passable The pipeline context bound by StepInvocationDispatcher::call().
     * @param Closure $next The dispatcher's identity closure.
     * @return mixed The forwarded $passable returned by $next (discarded).
     */
    public function handle(mixed $passable, Closure $next): mixed
    {
        self::$attempts++;

        if (self::$attempts < 2) {
            throw new RuntimeException('middleware-flaky');
        }

        if ($passable instanceof SimpleContext) {
            $passable->name = 'middleware-flaky-succeeded';
        }

        return $next($passable);
    }
}
