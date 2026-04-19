<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

/**
 * Middleware-shape fixture using InteractsWithPipeline trait (Story 9.4 AC #12).
 *
 * Asserts that the trait's `pipelineContext()` returns the same instance as
 * the dispatcher-bound `$passable` argument, then mutates the context.
 */
final class MiddlewareTraitEnrichJob
{
    use InteractsWithPipeline;

    /**
     * Verify the trait + middleware shape both expose the same context, then mutate it.
     *
     * @param mixed $passable The pipeline context bound by StepInvocationDispatcher::call().
     * @param Closure $next The dispatcher's identity closure.
     * @return mixed The forwarded $passable returned by $next (discarded by the pipeline).
     */
    public function handle(mixed $passable, Closure $next): mixed
    {
        if ($passable instanceof SimpleContext) {
            if ($this->pipelineContext() !== $passable) {
                throw new \LogicException('Trait pipelineContext() must equal $passable for middleware shape.');
            }

            $passable->name = 'middleware-trait-enriched';
        }

        return $next($passable);
    }
}
