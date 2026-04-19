<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;

/**
 * Immutable value object describing a pipeline-level concurrency-limit policy.
 *
 * Carries the user-supplied key (a literal non-empty string OR a Closure
 * resolved at admission time against the live PipelineContext) and the
 * maximum number of pipelines allowed to run simultaneously on the same
 * resolved key. Built and stored verbatim by PipelineBuilder::maxConcurrent()
 * and propagated onto PipelineDefinition for the gate to consume at run() /
 * toListener() admission time.
 */
final class ConcurrencyPolicy
{
    /**
     * Construct an immutable concurrency policy.
     *
     * Validation of $limit positivity and $key string non-emptiness happens
     * at the calling-builder level (PipelineBuilder::maxConcurrent()) so
     * malformed inputs surface where the user typed them rather than at
     * admission time.
     *
     * @param string|Closure(?PipelineContext): mixed $key Literal key string OR closure invoked with the resolved context; the closure SHOULD return a non-empty string but the return type is documented as mixed because user-supplied closures are validated at admission time via resolveKey() rather than enforced statically.
     * @param int $limit Maximum number of pipelines admitted simultaneously on the same key; must be >= 1.
     */
    public function __construct(
        public readonly string|Closure $key,
        public readonly int $limit,
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
     * @return string The resolved key string fed to PipelineConcurrencyGate::cacheKey().
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
            throw InvalidPipelineDefinition::keyResolverReturnedInvalidValue('maxConcurrent', $resolved);
        }

        return $resolved;
    }
}
