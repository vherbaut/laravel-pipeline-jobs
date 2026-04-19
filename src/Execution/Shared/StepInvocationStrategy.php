<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Shared;

/**
 * Strategy discriminator for how a pipeline step's underlying class is invoked.
 *
 * See `StepInvocationDispatcher::detect()` for selection logic and
 * `StepInvocationDispatcher::call()` for the per-strategy dispatch.
 *
 * @internal
 */
enum StepInvocationStrategy
{
    /**
     * `handle()` method is present and is NOT middleware-shape.
     *
     * Invoked via `app()->call([$job, 'handle'])`. This is the legacy
     * contract used by every pre-Story-9.4 pipeline step.
     */
    case Default;

    /**
     * `handle($passable, Closure $next)` is present (Laravel Pipeline / middleware contract).
     *
     * Invoked via
     * `app()->call([$job, 'handle'], ['passable' => $context, 'next' => identity-closure])`.
     * The `$next` argument is an identity closure that returns its
     * argument unchanged because pipeline ordering is managed by the
     * manifest, not by middleware-style chaining.
     */
    case Middleware;

    /**
     * `__invoke()` is present and `handle()` is absent (invokable Action contract).
     *
     * Invoked via `app()->call($job, ['context' => $context])`. The
     * `'context'` parameter binding lets users declare
     * `__invoke(?PipelineContext $context)` and receive the live
     * pipeline context by parameter name.
     */
    case Action;
}
