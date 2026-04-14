<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\Testing\FakePipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Testing\PipelineFake;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;

it('forwards onFailure() to the underlying builder and preserves fluent chain', function () {
    $fake = new PipelineFake;
    $fakeBuilder = new FakePipelineBuilder($fake, [FakeJobA::class]);

    $result = $fakeBuilder->onFailure(FailStrategy::StopAndCompensate);

    expect($result)->toBe($fakeBuilder)
        ->and($fakeBuilder->build()->failStrategy)->toBe(FailStrategy::StopAndCompensate);
});

it('defaults the strategy to FailStrategy::StopImmediately when fake builder never calls onFailure()', function () {
    $fake = new PipelineFake;
    $fakeBuilder = new FakePipelineBuilder($fake, [FakeJobA::class]);

    expect($fakeBuilder->build()->failStrategy)->toBe(FailStrategy::StopImmediately);
});
