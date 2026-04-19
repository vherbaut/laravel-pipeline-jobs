<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

/**
 * Middleware-shape fixture for Story 9.4 (FR44).
 *
 * Mutates the SimpleContext name to "middleware-enriched" then yields to the
 * dispatcher's identity-closure $next. The return value is discarded by the
 * pipeline; calling $next() exists to keep the canonical Laravel middleware
 * shape rather than to transfer control.
 */
final class MiddlewareEnrichJob
{
    /** @var int Static execution counter; tests reset this in beforeEach. */
    public static int $invocations = 0;

    /**
     * Set the passable's $name to "middleware-enriched" and forward via $next.
     *
     * @param mixed $passable The pipeline context resolved by StepInvocationDispatcher::call().
     * @param Closure $next The dispatcher's identity closure; invoked to honor middleware contract.
     * @return mixed The forwarded $passable returned by $next (discarded by the pipeline).
     */
    public function handle(mixed $passable, Closure $next): mixed
    {
        self::$invocations++;

        if ($passable instanceof SimpleContext) {
            $passable->name = 'middleware-enriched';
        }

        return $next($passable);
    }
}
