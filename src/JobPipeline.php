<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

/**
 * Main entry point for building and dispatching job pipelines.
 *
 * This class provides the static API for creating pipeline builders
 * from arrays of job class names.
 */
final class JobPipeline
{
    /**
     * Create a new pipeline builder from an array of job class names.
     *
     * @param array<int, string> $jobs Fully qualified job class names to add as pipeline steps.
     */
    public static function make(array $jobs = []): PipelineBuilder
    {
        return new PipelineBuilder($jobs);
    }
}
