<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;

it('returns a PipelineBuilder instance from make()', function () {
    $builder = JobPipeline::make([FakeJobA::class]);

    expect($builder)->toBeInstanceOf(PipelineBuilder::class);
});

it('returns an empty PipelineBuilder when make() is called with no args', function () {
    $builder = JobPipeline::make();

    expect($builder)->toBeInstanceOf(PipelineBuilder::class);
});

it('preserves step order from make() input array', function () {
    $builder = JobPipeline::make([FakeJobA::class, FakeJobB::class, FakeJobC::class]);

    $definition = $builder->build();

    expect($definition->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobC::class);
});
