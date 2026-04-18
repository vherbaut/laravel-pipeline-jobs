<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use Vherbaut\LaravelPipelineJobs\ConditionalBranch;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\NestedPipeline;
use Vherbaut\LaravelPipelineJobs\ParallelStepGroup;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

/**
 * PHPUnit assertion methods for verifying pipeline dispatches in tests.
 *
 * Used by PipelineFake to provide Bus::fake()-style assertion capabilities.
 * All assertion failures use PHPUnit\Framework\Assert for proper
 * Pest/PHPUnit integration with clear, actionable messages.
 */
trait PipelineAssertions
{
    /**
     * Assert that at least one pipeline was dispatched.
     *
     * When a callback is provided, at least one recorded pipeline must
     * satisfy the callback (receiving a PipelineDefinition, returning bool).
     *
     * @param Closure(PipelineDefinition): bool|null $callback Optional filter that receives PipelineDefinition and returns true for a match.
     * @return void
     */
    public function assertPipelineRan(?Closure $callback = null): void
    {
        $recorded = $this->recordedPipelines();

        if ($callback === null) {
            PHPUnit::assertNotEmpty(
                $recorded,
                'Expected at least one pipeline to have been dispatched, but none were.',
            );

            return;
        }

        $matched = array_filter(
            $recorded,
            fn (RecordedPipeline $recorded): bool => $callback($recorded->definition),
        );

        PHPUnit::assertNotEmpty(
            $matched,
            sprintf(
                'Expected at least one pipeline matching the callback, but none of the %d recorded pipeline(s) matched.',
                count($recorded),
            ),
        );
    }

    /**
     * Assert that a pipeline was dispatched containing exactly the given job classes in order.
     *
     * Compares the StepDefinition::$jobClass values from each recorded
     * PipelineDefinition against the expected array.
     *
     * @param array<int, string> $expectedJobs Fully qualified job class names in expected order.
     * @return void
     */
    public function assertPipelineRanWith(array $expectedJobs): void
    {
        $recorded = $this->recordedPipelines();

        PHPUnit::assertNotEmpty(
            $recorded,
            sprintf(
                'Expected a pipeline with jobs [%s], but no pipelines were dispatched.',
                implode(', ', array_map(fn (string $class): string => class_basename($class), $expectedJobs)),
            ),
        );

        $found = false;

        foreach ($recorded as $recordedPipeline) {
            $actualJobs = array_map(
                fn (StepDefinition $step): string => $step->jobClass,
                $recordedPipeline->definition->steps,
            );

            if ($actualJobs === $expectedJobs) {
                $found = true;

                break;
            }
        }

        if (! $found) {
            $allRecorded = array_map(
                fn (RecordedPipeline $rp): string => '['.implode(', ', array_map(
                    fn (StepDefinition $step): string => class_basename($step->jobClass),
                    $rp->definition->steps,
                )).']',
                $recorded,
            );

            PHPUnit::assertSame(
                implode(', ', array_map(fn (string $class): string => class_basename($class), $expectedJobs)),
                implode(' | ', $allRecorded),
                sprintf(
                    "No recorded pipeline matched the expected jobs.\n\nExpected: [%s]\nRecorded pipelines:\n%s",
                    implode(', ', array_map(fn (string $class): string => class_basename($class), $expectedJobs)),
                    implode("\n", array_map(fn (string $line, int $i): string => sprintf('  %d. %s', $i + 1, $line), $allRecorded, array_keys($allRecorded))),
                ),
            );
        }
    }

    /**
     * Assert that no pipelines were dispatched.
     *
     * @return void
     */
    public function assertNoPipelinesRan(): void
    {
        $recorded = $this->recordedPipelines();

        PHPUnit::assertEmpty(
            $recorded,
            sprintf(
                "Expected no pipelines to have been dispatched, but %d were recorded:\n%s",
                count($recorded),
                implode("\n", array_map(
                    fn (RecordedPipeline $rp, int $i): string => sprintf(
                        '  %d. [%s]',
                        $i + 1,
                        implode(', ', array_map(fn (StepDefinition $step): string => class_basename($step->jobClass), $rp->definition->steps)),
                    ),
                    $recorded,
                    array_keys($recorded),
                )),
            ),
        );
    }

    /**
     * Assert that exactly the given number of pipelines were dispatched.
     *
     * @param int $count Expected number of pipeline dispatches.
     * @return void
     */
    public function assertPipelineRanTimes(int $count): void
    {
        $actual = count($this->recordedPipelines());

        PHPUnit::assertSame(
            $count,
            $actual,
            sprintf(
                'Expected %d pipeline(s) to have been dispatched, but %d were.',
                $count,
                $actual,
            ),
        );
    }

    /**
     * Assert that a recorded pipeline contains a parallel group matching the expected sub-step class list.
     *
     * Walks the resolved pipeline's `$definition->steps`, locates any
     * ParallelStepGroup whose sub-steps' jobClass values match the expected
     * list in declaration order, and fails with a listing of the recorded
     * groups (by index) when no match is found. When no parallel group has
     * been recorded at all, fails with a clear "no parallel group recorded"
     * message.
     *
     * Available in default Pipeline::fake() mode and in recording mode
     * (does NOT require recording mode — the assertion inspects the
     * recorded definition, not execution traces).
     *
     * @param array<int, string> $expectedStepClasses Sub-step class-strings in expected declaration order.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertParallelGroupExecuted(array $expectedStepClasses, ?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $actualGroups = [];

        foreach ($recorded->definition->steps as $step) {
            if (! $step instanceof ParallelStepGroup) {
                continue;
            }

            $subClasses = array_map(
                static fn (StepDefinition $subStep): string => $subStep->jobClass,
                $step->steps,
            );

            $actualGroups[] = $subClasses;

            if ($subClasses === $expectedStepClasses) {
                return;
            }
        }

        if ($actualGroups === []) {
            PHPUnit::fail(sprintf(
                'Expected a recorded parallel group with sub-steps [%s], but the resolved pipeline recorded no parallel groups.',
                implode(', ', array_map(static fn (string $class): string => class_basename($class), $expectedStepClasses)),
            ));
        }

        PHPUnit::fail(sprintf(
            "Expected a recorded parallel group with sub-steps [%s], but none of the %d recorded group(s) matched.\n\nRecorded parallel groups:\n%s",
            implode(', ', array_map(static fn (string $class): string => class_basename($class), $expectedStepClasses)),
            count($actualGroups),
            implode("\n", array_map(
                static fn (array $subs, int $index): string => sprintf(
                    '  %d. [%s]',
                    $index + 1,
                    implode(', ', array_map(static fn (string $class): string => class_basename($class), $subs)),
                ),
                $actualGroups,
                array_keys($actualGroups),
            )),
        ));
    }

    /**
     * Assert that a recorded pipeline contains a nested group matching the expected inner-step class list.
     *
     * Walks the resolved pipeline's `$definition->steps`, locates any
     * NestedPipeline wrapper whose flattened inner-step class names
     * (recursive across parallel AND nested sub-groups) match the expected
     * list in declaration order, and optionally filters by the wrapper's
     * `$name` slot when `$name !== null`. Fails with a listing of the
     * recorded nested groups when no match is found. When no nested group
     * has been recorded at all, fails with a clear "no nested group
     * recorded" message.
     *
     * Available in default Pipeline::fake() mode and in recording mode
     * (does NOT require recording mode — the assertion inspects the
     * recorded definition, not execution traces).
     *
     * @param array<int, string> $expectedStepClasses Inner-step class-strings in expected declaration order (recursive flatten across parallel and nested sub-groups).
     * @param string|null $name Optional nested-pipeline name to match; null matches any name.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertNestedPipelineExecuted(array $expectedStepClasses, ?string $name = null, ?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $actualGroups = [];

        foreach ($recorded->definition->steps as $step) {
            if (! $step instanceof NestedPipeline) {
                continue;
            }

            if ($name !== null && $step->name !== $name) {
                continue;
            }

            $flattened = $this->flattenNestedDefinitionSteps($step->definition);

            $actualGroups[] = ['name' => $step->name, 'classes' => $flattened];

            if ($flattened === $expectedStepClasses) {
                return;
            }
        }

        $nameSuffix = $name !== null ? sprintf(' with name "%s"', $name) : '';
        $expectedLabel = implode(', ', array_map(static fn (string $class): string => class_basename($class), $expectedStepClasses));

        if ($actualGroups === []) {
            PHPUnit::fail(sprintf(
                'Expected a recorded nested pipeline%s with inner steps [%s], but the resolved pipeline recorded no nested pipelines.',
                $nameSuffix,
                $expectedLabel,
            ));
        }

        PHPUnit::fail(sprintf(
            "Expected a recorded nested pipeline%s with inner steps [%s], but none of the %d recorded nested pipeline(s) matched.\n\nRecorded nested pipelines:\n%s",
            $nameSuffix,
            $expectedLabel,
            count($actualGroups),
            implode("\n", array_map(
                static fn (array $group, int $index): string => sprintf(
                    '  %d. (name: %s) [%s]',
                    $index + 1,
                    $group['name'] ?? '<null>',
                    implode(', ', array_map(static fn (string $class): string => class_basename($class), $group['classes'])),
                ),
                $actualGroups,
                array_keys($actualGroups),
            )),
        ));
    }

    /**
     * Assert that a recorded pipeline contains a conditional branch group matching the expected branch keys.
     *
     * Walks the resolved pipeline's `$definition->steps`, locates any
     * ConditionalBranch wrapper whose declared branch keys match
     * `$expectedKeys` exactly (order-sensitive since PHP arrays preserve
     * insertion order), and optionally filters by the wrapper's `$name`
     * slot when `$name !== null`. Fails with a listing of the recorded
     * branch groups when no match is found. When no branch group was
     * recorded at all, fails with a clear "no conditional branches
     * recorded" message.
     *
     * Available in default Pipeline::fake() mode and in recording mode
     * (does NOT require recording mode — the assertion inspects the
     * recorded definition, not execution traces).
     *
     * @param array<int, string> $expectedKeys Branch keys in expected declaration order.
     * @param string|null $name Optional branch group name to match; null matches any name.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertConditionalBranchExecuted(array $expectedKeys, ?string $name = null, ?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $actualGroups = [];

        foreach ($recorded->definition->steps as $step) {
            if (! $step instanceof ConditionalBranch) {
                continue;
            }

            if ($name !== null && $step->name !== $name) {
                continue;
            }

            $keys = array_keys($step->branches);

            $actualGroups[] = ['name' => $step->name, 'keys' => $keys];

            if ($keys === $expectedKeys) {
                return;
            }
        }

        $nameSuffix = $name !== null ? sprintf(' with name "%s"', $name) : '';
        $expectedLabel = implode(', ', array_map(static fn (string $key): string => '"'.$key.'"', $expectedKeys));

        if ($actualGroups === []) {
            PHPUnit::fail(sprintf(
                'Expected a recorded conditional branch%s with keys [%s], but the resolved pipeline recorded no conditional branches.',
                $nameSuffix,
                $expectedLabel,
            ));
        }

        PHPUnit::fail(sprintf(
            "Expected a recorded conditional branch%s with keys [%s], but none of the %d recorded branch group(s) matched.\n\nRecorded conditional branches:\n%s",
            $nameSuffix,
            $expectedLabel,
            count($actualGroups),
            implode("\n", array_map(
                static fn (array $group, int $index): string => sprintf(
                    '  %d. (name: %s) [%s]',
                    $index + 1,
                    $group['name'] ?? '<null>',
                    implode(', ', array_map(static fn (string $key): string => '"'.$key.'"', $group['keys'])),
                ),
                $actualGroups,
                array_keys($actualGroups),
            )),
        ));
    }

    /**
     * Recursively flatten a nested pipeline's inner steps to a flat class-name list.
     *
     * Used by assertNestedPipelineExecuted() to match the expected flat
     * list against the recorded nested tree. Parallel sub-groups expand to
     * their sub-steps' class names in declaration order; NestedPipeline
     * sub-entries recurse through this helper transitively.
     *
     * @param PipelineDefinition $definition The inner pipeline definition whose steps are flattened.
     *
     * @return array<int, string> Flat class-name list in declaration order.
     */
    private function flattenNestedDefinitionSteps(PipelineDefinition $definition): array
    {
        $flat = [];

        foreach ($definition->steps as $inner) {
            if ($inner instanceof ParallelStepGroup) {
                foreach ($inner->steps as $subStep) {
                    $flat[] = $subStep->jobClass;
                }

                continue;
            }

            if ($inner instanceof NestedPipeline) {
                foreach ($this->flattenNestedDefinitionSteps($inner->definition) as $deeper) {
                    $flat[] = $deeper;
                }

                continue;
            }

            $flat[] = $inner->jobClass;
        }

        return $flat;
    }

    /**
     * Assert that the given job class was executed during a recorded pipeline run.
     *
     * Requires recording mode (Pipeline::fake()->recording()). Fails with
     * a clear message listing the actual executed steps if the job was not found.
     *
     * @param string $jobClass Fully qualified job class name expected to have executed.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertStepExecuted(string $jobClass, ?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $this->assertRecordingMode($recorded);

        PHPUnit::assertContains(
            $jobClass,
            $recorded->executedSteps,
            sprintf(
                "Expected step [%s] to have been executed, but it was not.\n\nActual executed steps: [%s]",
                class_basename($jobClass),
                implode(', ', array_map(fn (string $s): string => class_basename($s), $recorded->executedSteps)),
            ),
        );
    }

    /**
     * Assert that the given job class was NOT executed during a recorded pipeline run.
     *
     * Requires recording mode (Pipeline::fake()->recording()).
     *
     * @param string $jobClass Fully qualified job class name expected to NOT have executed.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertStepNotExecuted(string $jobClass, ?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $this->assertRecordingMode($recorded);

        PHPUnit::assertNotContains(
            $jobClass,
            $recorded->executedSteps,
            sprintf(
                'Expected step [%s] to NOT have been executed, but it was present in the executed steps.',
                class_basename($jobClass),
            ),
        );
    }

    /**
     * Assert that the given job classes were executed in exactly the given order.
     *
     * Requires recording mode (Pipeline::fake()->recording()). Fails with
     * actual vs expected comparison on mismatch.
     *
     * @param array<int, string> $expectedJobs Fully qualified job class names in expected execution order.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertStepsExecutedInOrder(array $expectedJobs, ?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $this->assertRecordingMode($recorded);

        PHPUnit::assertSame(
            $expectedJobs,
            $recorded->executedSteps,
            sprintf(
                "Steps were not executed in the expected order.\n\nExpected: [%s]\nActual:   [%s]",
                implode(', ', array_map(fn (string $s): string => class_basename($s), $expectedJobs)),
                implode(', ', array_map(fn (string $s): string => class_basename($s), $recorded->executedSteps)),
            ),
        );
    }

    /**
     * Assert that the recorded context has a property matching the expected value.
     *
     * In recording mode, checks the final context after execution.
     * In fake mode, checks the sent context.
     *
     * @param string $property The property name to check on the context.
     * @param mixed $expected The expected value of the property.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertContextHas(string $property, mixed $expected, ?int $pipelineIndex = null): void
    {
        $context = $this->getRecordedContext($pipelineIndex);

        PHPUnit::assertNotNull(
            $context,
            'No context was recorded for this pipeline. Ensure send() was called with a PipelineContext.',
        );

        PHPUnit::assertTrue(
            property_exists($context, $property),
            sprintf(
                'Property [%s] does not exist on context class [%s].',
                $property,
                $context::class,
            ),
        );

        PHPUnit::assertSame(
            $expected,
            $context->{$property},
            sprintf(
                "Context property [%s] does not match expected value.\n\nExpected: %s\nActual:   %s",
                $property,
                var_export($expected, true),
                var_export($context->{$property}, true),
            ),
        );
    }

    /**
     * Assert that the recorded context satisfies the given callback.
     *
     * The callback receives the recorded PipelineContext and must return true.
     * In recording mode, receives the final context. In fake mode, receives
     * the sent context.
     *
     * @param Closure(PipelineContext): bool $callback A closure that receives the context and returns true if satisfied.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertContext(Closure $callback, ?int $pipelineIndex = null): void
    {
        $context = $this->getRecordedContext($pipelineIndex);

        PHPUnit::assertNotNull(
            $context,
            'No context was recorded for this pipeline. Ensure send() was called with a PipelineContext.',
        );

        $result = $callback($context);

        PHPUnit::assertTrue(
            $result,
            'The context assertion callback returned false. No matching context found.',
        );
    }

    /**
     * Get the context snapshot captured immediately after a specific step completed.
     *
     * Only available in recording mode (Pipeline::fake()->recording()).
     * Returns a deep clone of the context as it was after the step finished.
     * When a step appears multiple times, returns the snapshot from the last occurrence.
     *
     * @param string $jobClass Fully qualified class name of the step to get the snapshot for.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return PipelineContext The cloned context snapshot.
     */
    public function getContextAfterStep(string $jobClass, ?int $pipelineIndex = null): PipelineContext
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $wasRecording = $recorded->wasRecording;

        PHPUnit::assertTrue(
            $wasRecording,
            sprintf(
                'No context snapshots available. Context snapshots require Pipeline::fake()->recording() mode. Step [%s] has no snapshot.',
                class_basename($jobClass),
            ),
        );

        $indices = array_keys($recorded->executedSteps, $jobClass, true);

        PHPUnit::assertNotEmpty(
            $indices,
            sprintf(
                "No context snapshot found for step [%s].\n\nExecuted steps: [%s]",
                class_basename($jobClass),
                implode(', ', array_map(fn (string $s): string => class_basename($s), $recorded->executedSteps)),
            ),
        );

        /** @var int $lastIndex */
        $lastIndex = end($indices);

        PHPUnit::assertArrayHasKey(
            $lastIndex,
            $recorded->contextSnapshots,
            sprintf(
                'Context snapshot at index %d not found for step [%s]. The step may have run without a context.',
                $lastIndex,
                class_basename($jobClass),
            ),
        );

        return $recorded->contextSnapshots[$lastIndex];
    }

    /**
     * Assert that compensation was triggered during a recorded pipeline run.
     *
     * Requires recording mode (Pipeline::fake()->recording()). Fails with a
     * clear message if no compensation logic executed after the failure.
     *
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertCompensationWasTriggered(?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $this->assertRecordingMode($recorded);

        PHPUnit::assertTrue(
            $recorded->compensationTriggered,
            'Expected compensation to have been triggered, but it was not.',
        );
    }

    /**
     * Assert that compensation was NOT triggered during a recorded pipeline run.
     *
     * Requires recording mode (Pipeline::fake()->recording()). Fails if
     * compensation was triggered.
     *
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertCompensationNotTriggered(?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $this->assertRecordingMode($recorded);

        PHPUnit::assertFalse(
            $recorded->compensationTriggered,
            sprintf(
                "Expected compensation to NOT have been triggered, but it was.\n\nCompensation steps executed: [%s]",
                implode(', ', array_map(fn (string $s): string => class_basename($s), $recorded->compensationSteps)),
            ),
        );
    }

    /**
     * Assert that a specific compensation job was executed during a recorded pipeline run.
     *
     * Requires recording mode (Pipeline::fake()->recording()). Fails with a
     * clear message listing actual compensation jobs if the given class was not found.
     *
     * @param string $jobClass Fully qualified class name of the compensation job expected to have run.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertCompensationRan(string $jobClass, ?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $this->assertRecordingMode($recorded);

        PHPUnit::assertContains(
            $jobClass,
            $recorded->compensationSteps,
            sprintf(
                "Expected compensation job [%s] to have been executed, but it was not.\n\nActual compensation steps: [%s]",
                class_basename($jobClass),
                implode(', ', array_map(fn (string $s): string => class_basename($s), $recorded->compensationSteps)),
            ),
        );
    }

    /**
     * Assert that a specific compensation job was NOT executed during a recorded pipeline run.
     *
     * Requires recording mode (Pipeline::fake()->recording()). Fails if the
     * given compensation class was found in the executed compensation steps.
     *
     * @param string $jobClass Fully qualified class name of the compensation job expected to NOT have run.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertCompensationNotRan(string $jobClass, ?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $this->assertRecordingMode($recorded);

        PHPUnit::assertNotContains(
            $jobClass,
            $recorded->compensationSteps,
            sprintf(
                'Expected compensation job [%s] to NOT have been executed, but it was present in the compensation steps.',
                class_basename($jobClass),
            ),
        );
    }

    /**
     * Assert that compensation jobs were executed in exactly the given order.
     *
     * Requires recording mode (Pipeline::fake()->recording()). Fails with
     * actual vs expected comparison on mismatch.
     *
     * @param array<int, string> $expectedJobs Fully qualified compensation class names in expected execution order.
     * @param int|null $pipelineIndex 0-based index, or null for the most recent pipeline.
     * @return void
     */
    public function assertCompensationExecutedInOrder(array $expectedJobs, ?int $pipelineIndex = null): void
    {
        $recorded = $this->resolveRecordedPipeline($pipelineIndex);

        $this->assertRecordingMode($recorded);

        PHPUnit::assertSame(
            $expectedJobs,
            $recorded->compensationSteps,
            sprintf(
                "Compensation steps were not executed in the expected order.\n\nExpected: [%s]\nActual:   [%s]",
                implode(', ', array_map(fn (string $s): string => class_basename($s), $expectedJobs)),
                implode(', ', array_map(fn (string $s): string => class_basename($s), $recorded->compensationSteps)),
            ),
        );
    }

    /**
     * Assert that recording mode was active for the given recorded pipeline.
     *
     * @param RecordedPipeline $recorded The recorded pipeline to check.
     * @return void
     */
    private function assertRecordingMode(RecordedPipeline $recorded): void
    {
        $wasRecording = $recorded->wasRecording;

        PHPUnit::assertTrue(
            $wasRecording,
            'Step execution assertions require Pipeline::fake()->recording() mode. No execution data was recorded.',
        );
    }
}
