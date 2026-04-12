<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use ReflectionProperty;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineExecutor;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;

/**
 * Test executor that runs all steps synchronously while capturing
 * per-step context snapshots for assertion.
 *
 * Follows the same execution flow as SyncExecutor: iterates step classes
 * in order, instantiates via the container, injects the manifest, and
 * calls handle(). After each step completes, a deep clone of the current
 * context is stored in execution order.
 *
 * This executor lives in the Testing namespace and is only used when
 * Pipeline::fake()->recording() mode is active.
 */
final class RecordingExecutor implements PipelineExecutor
{
    /** @var array<int, string> */
    private array $executedSteps = [];

    /** @var array<int, PipelineContext> */
    private array $contextSnapshots = [];

    private bool $compensationTriggered = false;

    /** @var array<int, string> */
    private array $compensationSteps = [];

    /**
     * Execute all steps synchronously, capturing context snapshots after each step.
     *
     * @param PipelineDefinition $definition The immutable pipeline description containing steps and configuration.
     * @param PipelineManifest $manifest The mutable execution state carrying context and step progress.
     * @return PipelineContext|null The final pipeline context after execution, or null if no context was set.
     *
     * @throws StepExecutionFailed When any step throws an exception during execution.
     */
    public function execute(PipelineDefinition $definition, PipelineManifest $manifest): ?PipelineContext
    {
        foreach ($manifest->stepClasses as $stepClass) {
            try {
                $job = app()->make($stepClass);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                app()->call([$job, 'handle']);

                $manifest->markStepCompleted($stepClass);
                $manifest->advanceStep();

                $this->executedSteps[] = $stepClass;

                if ($manifest->context !== null) {
                    $this->contextSnapshots[] = unserialize(serialize($manifest->context));
                }
            } catch (Throwable $exception) {
                $this->runCompensation($manifest);

                throw StepExecutionFailed::forStep(
                    $manifest->pipelineId,
                    $manifest->currentStepIndex,
                    $stepClass,
                    $exception,
                );
            }
        }

        return $manifest->context;
    }

    /**
     * Get the ordered list of step class names that completed execution.
     *
     * @return array<int, string> Fully qualified class names in execution order.
     */
    public function executedSteps(): array
    {
        return $this->executedSteps;
    }

    /**
     * Get the per-step context snapshots captured during execution.
     *
     * Each snapshot is a deep clone of the context as it was immediately
     * after the corresponding step completed, stored in execution order.
     *
     * @return array<int, PipelineContext> Snapshots in execution order.
     */
    public function contextSnapshots(): array
    {
        return $this->contextSnapshots;
    }

    /**
     * Check whether compensation was triggered during execution.
     *
     * @return bool True if at least one compensation job was executed.
     */
    public function compensationTriggered(): bool
    {
        return $this->compensationTriggered;
    }

    /**
     * Get the ordered list of compensation job class names that were executed.
     *
     * @return array<int, string> Compensation classes in execution order (reverse of completed steps).
     */
    public function compensationSteps(): array
    {
        return $this->compensationSteps;
    }

    /**
     * Run compensation jobs for completed steps in reverse order.
     *
     * Only compensates steps that completed AND have a compensation mapping
     * defined in the manifest. Each compensation job is instantiated via the
     * container, injected with the manifest, and called synchronously.
     *
     * @param PipelineManifest $manifest The pipeline manifest containing compensation mapping.
     * @return void
     */
    private function runCompensation(PipelineManifest $manifest): void
    {
        if ($manifest->compensationMapping === []) {
            return;
        }

        $reversedCompleted = array_reverse($manifest->completedSteps);

        foreach ($reversedCompleted as $completedStep) {
            if (! isset($manifest->compensationMapping[$completedStep])) {
                continue;
            }

            $compensationClass = $manifest->compensationMapping[$completedStep];

            try {
                $job = app()->make($compensationClass);

                if (property_exists($job, 'pipelineManifest')) {
                    $property = new ReflectionProperty($job, 'pipelineManifest');
                    $property->setValue($job, $manifest);
                }

                app()->call([$job, 'handle']);

                $this->compensationSteps[] = $compensationClass;
            } catch (Throwable) {
                $this->compensationSteps[] = $compensationClass;
            }
        }

        if ($this->compensationSteps !== []) {
            $this->compensationTriggered = true;
        }
    }
}
