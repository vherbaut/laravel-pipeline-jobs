<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;

/**
 * Immutable value object describing a pipeline-level rate-limit policy.
 *
 * Carries the user-supplied key (a literal non-empty string OR a Closure
 * resolved at admission time against the live PipelineContext), the
 * maximum number of admitted run() invocations per window, and the window
 * length in seconds. Built and stored verbatim by PipelineBuilder::rateLimit()
 * and propagated onto PipelineDefinition for the gate to consume at run() /
 * toListener() admission time.
 */
final class RateLimitPolicy
{
    /**
     * Construct an immutable rate-limit policy.
     *
     * Validation of $max / $perSeconds positivity and $key string non-emptiness
     * happens at the calling-builder level (PipelineBuilder::rateLimit()) so
     * malformed inputs surface where the user typed them rather than at
     * admission time.
     *
     * @param string|Closure(?PipelineContext): mixed $key Literal key string OR closure invoked with the resolved context; the closure SHOULD return a non-empty string but the return type is documented as mixed because user-supplied closures are validated at admission time via resolveKey() rather than enforced statically.
     * @param int $max Maximum admitted run() invocations per window; must be >= 1.
     * @param int $perSeconds Window length in seconds; must be >= 1.
     */
    public function __construct(
        public readonly string|Closure $key,
        public readonly int $max,
        public readonly int $perSeconds,
    ) {}

    /**
     * Resolve the key string for the current admission attempt.
     *
     * For a literal-string key, returns it verbatim. For a closure key,
     * invokes the closure with the resolved PipelineContext (which may be
     * null when the pipeline is admitted without a context, e.g. listener
     * mode without send()) and validates the return is a non-empty string.
     *
     * @param PipelineContext|null $context The live pipeline context at admission time, or null.
     *
     * @return string The resolved key string used by the rate-limiter facade.
     *
     * @throws InvalidPipelineDefinition When the closure returns a non-string or empty/whitespace string.
     */
    public function resolveKey(?PipelineContext $context): string
    {
        if (is_string($this->key)) {
            return $this->key;
        }

        $resolved = ($this->key)($context);

        if (! is_string($resolved) || trim($resolved) === '') {
            throw InvalidPipelineDefinition::keyResolverReturnedInvalidValue('rateLimit', $resolved);
        }

        return $resolved;
    }
}
