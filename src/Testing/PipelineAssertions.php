<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
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

        $matched = array_filter($recorded, $callback);

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

        foreach ($recorded as $definition) {
            $actualJobs = array_map(
                fn (StepDefinition $step): string => $step->jobClass,
                $definition->steps,
            );

            if ($actualJobs === $expectedJobs) {
                $found = true;

                break;
            }
        }

        if (! $found) {
            $allRecorded = array_map(
                fn (PipelineDefinition $def): string => '['.implode(', ', array_map(
                    fn (StepDefinition $step): string => class_basename($step->jobClass),
                    $def->steps,
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
                    fn (PipelineDefinition $def, int $i): string => sprintf(
                        '  %d. [%s]',
                        $i + 1,
                        implode(', ', array_map(fn (StepDefinition $step): string => class_basename($step->jobClass), $def->steps)),
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
}
