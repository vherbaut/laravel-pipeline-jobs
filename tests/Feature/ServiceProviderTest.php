<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineServiceProvider;

it('registers the PipelineServiceProvider', function () {
    expect($this->app->getProviders(PipelineServiceProvider::class))
        ->not->toBeEmpty();
});

it('resolves JobPipeline from the container', function () {
    $pipeline = $this->app->make(JobPipeline::class);

    expect($pipeline)->toBeInstanceOf(JobPipeline::class);
});

it('resolves JobPipeline as a singleton', function () {
    $first = $this->app->make(JobPipeline::class);
    $second = $this->app->make(JobPipeline::class);

    expect($first)->toBe($second);
});

it('resolves JobPipeline via the Pipeline facade', function () {
    expect(Pipeline::getFacadeRoot())->toBeInstanceOf(JobPipeline::class);
});
