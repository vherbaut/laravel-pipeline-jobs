<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

/**
 * Middleware-shape fixture that does NOT call $next (Story 9.4 DD #2).
 *
 * Pins that pipelines do not honor middleware-style early termination: the
 * pipeline always advances to the next step regardless of whether the
 * middleware called $next, because step ordering is managed by the manifest.
 */
final class MiddlewareWithoutNextJob
{
    /**
     * Mutate the passable without invoking $next.
     *
     * @param mixed $passable The pipeline context bound by StepInvocationDispatcher::call().
     * @param Closure $next The dispatcher's identity closure (intentionally unused).
     * @return void
     */
    public function handle(mixed $passable, Closure $next): void
    {
        if ($passable instanceof SimpleContext) {
            $passable->name = 'middleware-without-next';
        }
    }
}
