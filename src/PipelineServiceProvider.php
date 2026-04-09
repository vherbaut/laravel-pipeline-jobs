<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Illuminate\Support\ServiceProvider;

class PipelineServiceProvider extends ServiceProvider
{
    /**
     * Register the pipeline services into the container.
     */
    public function register(): void
    {
        $this->app->singleton(JobPipeline::class);
    }
}
