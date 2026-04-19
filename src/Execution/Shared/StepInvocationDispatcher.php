<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Shared;

use Closure;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;

/**
 * Strategy-aware invocation helper for pipeline step classes.
 *
 * Detects whether a resolved step instance implements the default `handle()`,
 * the Laravel Pipeline / middleware `handle($passable, Closure $next)`, or the
 * invokable Action `__invoke()` shape, and dispatches the call through Laravel's
 * container. Detection is memoized per class to keep the hot path cheap on
 * queue workers that process many steps from the same class.
 *
 * See `StepInvocationStrategy` for the enum cases. Classes that implement
 * neither shape throw `InvalidPipelineDefinition` at the first invocation
 * attempt.
 *
 * @internal
 */
final class StepInvocationDispatcher
{
    /**
     * Process-scoped memoization of detected strategies, keyed by class string.
     *
     * Reflection results are deterministic per class definition, so caching is
     * correctness-neutral. The cache survives queue worker reuse (Laravel
     * workers process many jobs in one PHP process by default) and amortizes
     * the reflection cost across repeated invocations of the same class.
     *
     * @var array<class-string, StepInvocationStrategy>
     */
    private static array $cache = [];

    /**
     * Detect the invocation strategy for a resolved step instance.
     *
     * Selection rules (first match wins):
     * 1. `handle()` exists AND has >= 2 parameters AND the second parameter
     *    is typed exactly as `Closure` via `ReflectionNamedType` -> Middleware.
     * 2. `handle()` exists otherwise -> Default.
     * 3. `__invoke()` exists (and `handle()` does not) -> Action.
     * 4. Neither method exists -> throw InvalidPipelineDefinition.
     *
     * Detection looks ONLY at the second parameter's resolved type name; the
     * first parameter's type, the return type, and method modifiers are not
     * inspected. Union types (e.g. `Closure|string`) fall through to Default
     * because they are `ReflectionUnionType`, not `ReflectionNamedType`.
     *
     * @param object $job The resolved step instance.
     * @return StepInvocationStrategy The detected strategy.
     *
     * @throws InvalidPipelineDefinition When the class implements neither handle() nor __invoke().
     */
    public static function detect(object $job): StepInvocationStrategy
    {
        $class = $job::class;

        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        if (method_exists($job, 'handle')) {
            $reflection = new ReflectionMethod($job, 'handle');

            if ($reflection->getNumberOfParameters() >= 2) {
                $secondParam = $reflection->getParameters()[1];
                $type = $secondParam->getType();

                if ($type instanceof ReflectionNamedType && $type->getName() === Closure::class) {
                    return self::$cache[$class] = StepInvocationStrategy::Middleware;
                }
            }

            return self::$cache[$class] = StepInvocationStrategy::Default;
        }

        if (method_exists($job, '__invoke')) {
            return self::$cache[$class] = StepInvocationStrategy::Action;
        }

        throw InvalidPipelineDefinition::stepClassMissingInvocationMethod($class);
    }

    /**
     * Dispatch a step invocation through Laravel's container per the detected strategy.
     *
     * - `Default`     -> `app()->call([$job, 'handle'])`.
     * - `Middleware`  -> `app()->call([$job, 'handle'], ['passable' => $context, 'next' => identity-closure])`.
     * - `Action`      -> `app()->call($job, ['context' => $context])`.
     *
     * Return values are intentionally discarded because pipelines mutate
     * context by reference, not by return value (consistent with the legacy
     * `app()->call([$job, 'handle'])` site that also discards return values).
     * Throws from the underlying invocation propagate unchanged so the retry
     * loop and failure handlers in `StepInvoker::invokeWithRetry()` can
     * observe them.
     *
     * @param object $job The resolved step instance.
     * @param PipelineContext|null $context The live pipeline context, or null when no context was sent.
     * @return void
     *
     * @throws InvalidPipelineDefinition When the class implements neither handle() nor __invoke().
     * @throws Throwable When the underlying handle()/__invoke() throws.
     */
    public static function call(object $job, ?PipelineContext $context): void
    {
        $strategy = self::detect($job);

        match ($strategy) {
            StepInvocationStrategy::Default => app()->call([$job, 'handle']),
            StepInvocationStrategy::Middleware => app()->call(
                [$job, 'handle'],
                [
                    'passable' => $context,
                    'next' => static fn (mixed $passable): mixed => $passable,
                ],
            ),
            StepInvocationStrategy::Action => app()->call($job, ['context' => $context]),
        };
    }

    /**
     * Clear the strategy detection cache.
     *
     * Test-isolation helper; not called from production code. Test files that
     * exercise the dispatcher should call this in `beforeEach` to prevent
     * cross-test cache leakage when fixtures reuse class names that have
     * evolved between tests.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
