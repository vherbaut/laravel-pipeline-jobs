<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Testing;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\PipelineDefinition;

/**
 * Value object capturing a single pipeline execution record.
 *
 * Stores the pipeline definition alongside optional execution data:
 * the recorded context, the list of executed step class names, and
 * per-step context snapshots (deep-cloned after each step completes).
 *
 * In fake mode, recordedContext holds the sent context (before execution).
 * In recording mode, recordedContext holds the final context (after execution).
 *
 * Used internally by PipelineFake to support both fake mode
 * (definition + sent context only) and recording mode
 * (definition + full execution trace).
 */
final readonly class RecordedPipeline
{
    /**
     * Create a new recorded pipeline.
     *
     * @param PipelineDefinition $definition The immutable pipeline description.
     * @param PipelineContext|null $recordedContext The recorded context: sent context in fake mode, final context after execution in recording mode.
     * @param array<int, string> $executedSteps Ordered list of fully qualified job class names that completed execution.
     * @param array<int, PipelineContext> $contextSnapshots Per-step context snapshots in execution order, each a deep clone captured after the step completed.
     * @param bool $wasRecording Whether this pipeline was executed in recording mode.
     * @param bool $compensationTriggered Whether compensation was triggered during execution.
     * @param array<int, string> $compensationSteps Ordered list of compensation class names that were executed.
     */
    public function __construct(
        public PipelineDefinition $definition,
        public ?PipelineContext $recordedContext = null,
        public array $executedSteps = [],
        public array $contextSnapshots = [],
        public bool $wasRecording = false,
        public bool $compensationTriggered = false,
        public array $compensationSteps = [],
    ) {}
}
