<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Exceptions;

/**
 * Thrown when a pipeline definition is invalid or incomplete.
 */
class InvalidPipelineDefinition extends PipelineException
{
    /**
     * Create an exception for a pipeline defined with no steps.
     *
     * @return self
     */
    public static function emptySteps(): self
    {
        return new self('A pipeline must contain at least one step.');
    }
}
