<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Facades;

use Illuminate\Support\Facades\Facade;
use Vherbaut\LaravelPipelineJobs\JobPipeline;

/**
 * Facade proxy for JobPipeline providing a familiar Laravel entry point for building pipelines.
 *
 * @see JobPipeline
 *
 * @method static \Vherbaut\LaravelPipelineJobs\PipelineBuilder make(array<int, class-string> $jobs = [])
 * @method static void listen(class-string $eventClass, array<int, class-string> $jobs, \Closure|null $send = null)
 */
class Pipeline extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return JobPipeline::class;
    }
}
