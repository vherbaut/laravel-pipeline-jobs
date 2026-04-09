<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Facades;

use Illuminate\Support\Facades\Facade;
use Vherbaut\LaravelPipelineJobs\JobPipeline;

/**
 * @see JobPipeline
 */
class Pipeline extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return JobPipeline::class;
    }
}
