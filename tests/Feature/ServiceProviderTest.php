<?php

declare(strict_types=1);

use Illuminate\Foundation\AliasLoader;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PendingPipelineDispatch;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\PipelineServiceProvider;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobB;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\FakeJobC;

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

it('proxies Pipeline::make() to JobPipeline::make() and returns a PipelineBuilder', function () {
    $builder = Pipeline::make([FakeJobA::class, FakeJobB::class]);

    expect($builder)->toBeInstanceOf(PipelineBuilder::class);
});

it('preserves step order when jobs are passed via Pipeline::make()', function () {
    $definition = Pipeline::make([FakeJobA::class, FakeJobB::class, FakeJobC::class])->build();

    expect($definition->steps)->toHaveCount(3)
        ->and($definition->steps[0]->jobClass)->toBe(FakeJobA::class)
        ->and($definition->steps[1]->jobClass)->toBe(FakeJobB::class)
        ->and($definition->steps[2]->jobClass)->toBe(FakeJobC::class);
});

it('returns an empty PipelineBuilder when Pipeline::make() is called with no arguments', function () {
    expect(Pipeline::make())->toBeInstanceOf(PipelineBuilder::class);
});

it('resolves Pipeline facade and JobPipeline singleton to the same instance', function () {
    expect(Pipeline::getFacadeRoot())->toBe($this->app->make(JobPipeline::class));
});

it('registers the Pipeline root alias via package auto-discovery', function () {
    $aliases = AliasLoader::getInstance()->getAliases();

    expect($aliases)
        ->toHaveKey('Pipeline')
        ->and($aliases['Pipeline'])
        ->toBe(Pipeline::class);
});

it('keeps tests/TestCase.php getPackageAliases() in sync with composer.json extra.laravel.aliases', function () {
    $composerPath = dirname(__DIR__, 2).'/composer.json';

    expect(is_file($composerPath))->toBeTrue();

    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($composer, 'extra.laravel.aliases.Pipeline'))
        ->toBe(Pipeline::class);
});

it('resolves the root-namespace Pipeline alias via the alias loader at runtime', function () {
    expect(class_exists('Pipeline'))->toBeTrue()
        ->and(\Pipeline::getFacadeRoot())->toBeInstanceOf(JobPipeline::class);
});

it('Pipeline::dispatch() facade resolves to PendingPipelineDispatch', function (): void {
    Pipeline::fake();

    $wrapper = Pipeline::dispatch([]);

    expect($wrapper)->toBeInstanceOf(PendingPipelineDispatch::class);

    // Cancel on empty-builder wrapper (would throw InvalidPipelineDefinition via run()).
    $wrapper->cancel();
});
