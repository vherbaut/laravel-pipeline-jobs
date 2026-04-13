<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('returns null context and false presence when no manifest was injected', function () {
    $job = new class
    {
        use InteractsWithPipeline;
    };

    expect($job->pipelineContext())->toBeNull()
        ->and($job->hasPipelineContext())->toBeFalse();
});

it('returns null context and false presence when the injected manifest has a null context', function () {
    $job = new class
    {
        use InteractsWithPipeline;
    };

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\Noop'],
        context: null,
    );

    $property = new ReflectionProperty($job, 'pipelineManifest');
    $property->setValue($job, $manifest);

    expect($job->pipelineContext())->toBeNull()
        ->and($job->hasPipelineContext())->toBeFalse();
});

it('exposes the injected manifest context through pipelineContext()', function () {
    $job = new class
    {
        use InteractsWithPipeline;
    };

    $context = new SimpleContext;
    $context->name = 'hello';

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\Noop'],
        context: $context,
    );

    $property = new ReflectionProperty($job, 'pipelineManifest');
    $property->setValue($job, $manifest);

    expect($job->pipelineContext())->toBe($context)
        ->and($job->hasPipelineContext())->toBeTrue()
        ->and($job->pipelineContext()?->name)->toBe('hello');
});

it('returns the same context instance as the manifest carries (no clone)', function () {
    $job = new class
    {
        use InteractsWithPipeline;
    };

    $context = new SimpleContext;

    $manifest = PipelineManifest::create(
        stepClasses: ['App\\Jobs\\Noop'],
        context: $context,
    );

    $property = new ReflectionProperty($job, 'pipelineManifest');
    $property->setValue($job, $manifest);

    expect($job->pipelineContext())->toBe($manifest->context);

    $exposed = $job->pipelineContext();
    expect($exposed)->toBeInstanceOf(SimpleContext::class);
    /** @var SimpleContext $exposed */
    $exposed->name = 'mutated-through-accessor';

    expect($manifest->context?->name)->toBe('mutated-through-accessor');
});
