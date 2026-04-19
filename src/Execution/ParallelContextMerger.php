<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionProperty;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

/**
 * Pure helper producing a merged PipelineContext from a pre-batch baseline plus per-sub-step final contexts.
 *
 * Invoked by the post-batch `then` callback registered on each parallel
 * group's Bus::batch(). Walks the baseline context's public properties once
 * and, for each property, scans the ordered per-sub-step final contexts to
 * pick the winning value. The default strategy is
 * "shallow-overwrite by declaration order":
 *  - A sub-step "owns" a mutation when its final value differs from the
 *    pre-batch baseline AND the final value is non-null.
 *  - Conflicts (two sub-steps mutating the same property to different non-null
 *    values) resolve to the LATER sub-step in declaration order and emit a
 *    `Log::warning('Pipeline parallel context merge conflict', [...])` entry
 *    with pipelineId, groupIndex, and propertyName for observability (NFR6).
 *  - Null final values never overwrite a non-null baseline value.
 *
 * User-customizable merge strategies are deliberately out of scope;
 * a later release will add a user-facing override
 * via `ParallelStepGroup::mergeWith(Closure)`.
 *
 * The merger is pure aside from the conflict-log side effect: it produces a
 * fresh context clone and never mutates the baseline instance in place.
 * Complexity is O(properties × finalContexts); callers operating on
 * context classes with many public properties and large batches should
 * consider declaring finer-grained sub-step ownership at design time rather
 * than relying on the merger to arbitrate hot paths.
 *
 * Limitations worth pinning in user-facing docs:
 *  - Do NOT substitute the CLASS of the context inside a parallel sub-step
 *    (e.g. returning an EnrichedContext subclass from one sub-step when the
 *    baseline is SimpleContext). The merger reflects on the baseline class
 *    only, so subclass-specific properties are silently dropped at fan-in.
 *    The idiomatic pattern is to declare every possible property (nullable
 *    if optional) on the baseline class and mutate them in place.
 *  - Readonly properties (PHP 8.1+) cannot be reassigned via reflection
 *    after initialization. They are skipped by the merger so the fan-in
 *    does not fatal on a subclass that marks identity fields readonly.
 *  - Null is not treated as a valid mutation per AC #12 (shallow-overwrite
 *    by declaration order, non-null only). Sub-steps that clear a property
 *    to null have their write ignored; a `Log::debug` line is emitted when
 *    a non-null baseline value survives because of the null-skip so the
 *    drop is observable in debug mode.
 */
final class ParallelContextMerger
{
    /**
     * Merge a list of per-sub-step final contexts into a fresh copy of the pre-batch baseline.
     *
     * The baseline is deep-cloned via serialize/unserialize so the returned
     * instance is structurally independent from the argument (callers may
     * mutate the baseline afterwards without affecting the returned merged
     * context). When $finalContexts is empty, the clone is returned
     * unchanged (no properties to overwrite).
     *
     * Null baselines (from context-less pipelines) short-circuit to null:
     * a parallel group in a context-less pipeline has nothing to merge
     * because the sub-steps had no context to mutate in the first place.
     *
     * @param PipelineContext|null $baseline The context captured BEFORE the batch dispatched; may be null for context-less pipelines.
     * @param array<int, PipelineContext|null> $finalContexts Per-sub-step final contexts in declaration order. Null slots represent sub-steps that skipped or failed without persisting a context (treated as "no contribution").
     * @param string|null $pipelineId Optional pipeline identifier for conflict-log observability.
     * @param int|null $groupIndex Optional outer group position for conflict-log observability.
     *
     * @return PipelineContext|null A new PipelineContext instance carrying the merged public-property values, or null when $baseline was null.
     */
    public static function merge(
        ?PipelineContext $baseline,
        array $finalContexts,
        ?string $pipelineId = null,
        ?int $groupIndex = null,
    ): ?PipelineContext {
        if ($baseline === null) {
            return null;
        }

        /** @var PipelineContext $merged */
        $merged = unserialize(serialize($baseline));

        if ($finalContexts === []) {
            return $merged;
        }

        $reflection = new ReflectionClass($baseline);
        $publicProperties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($publicProperties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            // Readonly properties cannot be reassigned after initialization on
            // PHP 8.1+; reflection honors the restriction outside the declaring
            // constructor. Skip them rather than fatal the fan-in callback.
            if ($property->isReadOnly()) {
                continue;
            }

            $propertyName = $property->getName();
            $baselineValue = $property->getValue($baseline);
            $appliedBySubIndex = null;
            $appliedValue = $baselineValue;

            foreach ($finalContexts as $subIndex => $finalContext) {
                if (! $finalContext instanceof PipelineContext) {
                    continue;
                }

                if (! property_exists($finalContext, $propertyName)) {
                    continue;
                }

                $finalValue = $property->getValue($finalContext);

                if ($finalValue === null) {
                    // Null is not a valid mutation per AC #12. Log at debug
                    // level only when a non-null baseline value would have
                    // been overwritten, so genuine "intent to clear" attempts
                    // are observable without polluting logs when baseline was
                    // already null (no change would have occurred).
                    if ($baselineValue !== null) {
                        Log::debug('Pipeline parallel null-write ignored', [
                            'pipelineId' => $pipelineId,
                            'groupIndex' => $groupIndex,
                            'subIndex' => $subIndex,
                            'propertyName' => $propertyName,
                        ]);
                    }

                    continue;
                }

                if ($finalValue === $baselineValue) {
                    continue;
                }

                if ($appliedBySubIndex !== null && $appliedValue !== $finalValue) {
                    Log::warning('Pipeline parallel context merge conflict', [
                        'pipelineId' => $pipelineId,
                        'groupIndex' => $groupIndex,
                        'propertyName' => $propertyName,
                        'previousSubIndex' => $appliedBySubIndex,
                        'overridingSubIndex' => $subIndex,
                    ]);
                }

                $appliedValue = $finalValue;
                $appliedBySubIndex = $subIndex;
            }

            if ($appliedBySubIndex !== null) {
                $property->setValue($merged, $appliedValue);
            }
        }

        return $merged;
    }
}
