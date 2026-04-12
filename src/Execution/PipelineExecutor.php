<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;

/**
 * Contract for pipeline execution strategies.
 *
 * Implementations define how steps are run (synchronously,
 * queued, etc.) while the builder and definition remain
 * execution-agnostic.
 */
interface PipelineExecutor
{
    /**
     * Execute all steps defined in the pipeline.
     *
     * @param PipelineDefinition $definition The immutable pipeline description containing steps and configuration.
     * @param PipelineManifest $manifest The mutable execution state carrying context and step progress.
     *
     * @throws StepExecutionFailed When a step throws an exception.
     */
    public function execute(PipelineDefinition $definition, PipelineManifest $manifest): ?PipelineContext;
}
