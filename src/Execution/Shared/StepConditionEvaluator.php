<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Shared;

use Laravel\SerializableClosure\SerializableClosure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

/**
 * Shared evaluator for per-step when()/unless() conditions.
 *
 * Consolidates the four shouldSkip* methods previously duplicated across
 * SyncExecutor (shouldSkipStep, shouldSkipNestedFlatEntry, shouldSkipBranchEntry,
 * shouldSkipParallelSubStep), PipelineStepJob (shouldSkipAtCurrentPosition),
 * and ParallelStepJob (shouldSkipSubStep). All logic reduces to the same
 * primitive: unwrap a SerializableClosure, evaluate it against the context,
 * and apply the `negated` flag. Parallel sub-step callers wrap the core
 * evaluation in a try/catch for context-rich logging.
 *
 * Every method is static and stateless so the helper survives queue
 * serialization boundaries by never carrying instance state.
 *
 * @internal
 */
final class StepConditionEvaluator
{
    /**
     * Evaluate a top-level step's when()/unless() condition from the manifest.
     *
     * Returns false when no condition is registered for the index or when the
     * entry is the nested group shape (`type === 'parallel'`), because group
     * shapes carry per-child conditions evaluated separately by the group
     * runner. Otherwise unwraps the SerializableClosure, evaluates it against
     * the manifest's current context, and applies the `negated` flag.
     *
     * A throwing closure propagates so the surrounding catch block converts
     * it to the standard step-failure path.
     *
     * @param PipelineManifest $manifest The manifest carrying stepConditions and context.
     * @param int $stepIndex The zero-based index of the step being evaluated.
     * @return bool True when the step must be skipped, false when it should run.
     */
    public static function shouldSkipStep(PipelineManifest $manifest, int $stepIndex): bool
    {
        $entry = $manifest->stepConditions[$stepIndex] ?? null;

        if ($entry === null) {
            return false;
        }

        if (($entry['type'] ?? null) === 'parallel') {
            return false;
        }

        /** @var array{closure: SerializableClosure, negated: bool} $entry */
        return self::evaluateEntry($entry, $manifest->context);
    }

    /**
     * Evaluate a cursor-resolved step condition from a queued wrapper.
     *
     * Uses the manifest's cursor-aware `conditionAt()` when nested; falls
     * back to the outer-index lookup otherwise. Group-shape entries
     * (`type === 'nested' | 'parallel'`) at the outer level degrade to
     * "unconditional" so the wrapper does not misread a group envelope as a
     * flat closure. Mirrors `shouldSkipStep()` semantics otherwise.
     *
     * @param PipelineManifest $manifest The manifest carrying nested cursor, stepConditions, and context.
     * @return bool True when the step must be skipped, false when it should run.
     */
    public static function shouldSkipAtCursor(PipelineManifest $manifest): bool
    {
        if ($manifest->nestedCursor !== []) {
            $entry = $manifest->conditionAt($manifest->nestedCursor);
        } else {
            $outer = $manifest->stepConditions[$manifest->currentStepIndex] ?? null;
            $entry = (is_array($outer) && ! isset($outer['type'])) ? $outer : null;
        }

        if ($entry === null) {
            return false;
        }

        return self::evaluateEntry($entry, $manifest->context);
    }

    /**
     * Evaluate a flat `{closure, negated}` condition entry against a context.
     *
     * Null entry means "always run". Used for nested flat entries, branch
     * value entries, and parallel sub-step entries (each caller resolves
     * the entry from its enclosing envelope before delegating here).
     *
     * A throwing closure propagates unchanged so callers can attribute it
     * as a condition failure rather than an unrelated step error.
     *
     * @param array{closure: SerializableClosure, negated: bool}|null $entry The resolved condition entry or null when unconditional.
     * @param PipelineContext|null $context The live pipeline context at evaluation time.
     * @return bool True when the step must be skipped, false when it should run.
     */
    public static function shouldSkipEntry(?array $entry, ?PipelineContext $context): bool
    {
        if ($entry === null) {
            return false;
        }

        return self::evaluateEntry($entry, $context);
    }

    /**
     * Core closure evaluation shared by all public entry points.
     *
     * @param array{closure: SerializableClosure, negated: bool} $entry
     */
    private static function evaluateEntry(array $entry, ?PipelineContext $context): bool
    {
        $closure = $entry['closure']->getClosure();
        $result = (bool) $closure($context);
        $shouldRun = $entry['negated'] ? ! $result : $result;

        return ! $shouldRun;
    }
}
