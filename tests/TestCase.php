<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;
use Vherbaut\LaravelPipelineJobs\PipelineServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PipelineServiceProvider::class,
        ];
    }

    /**
     * Configure the test environment with deterministic defaults.
     *
     * The `array` cache driver is pinned explicitly so that facade spies on
     * `RateLimiter` and `Cache` can resolve the container singletons without
     * tripping over a null store when Testbench defaults vary between
     * Laravel minor versions.
     *
     * @param Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    /**
     * Get the package aliases registered in the test application.
     *
     * Mirrors the composer.json `extra.laravel.aliases` declaration so that
     * tests exercise the same root-level facade alias that real consumers
     * receive via Laravel package auto-discovery.
     *
     * @param Application $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Pipeline' => Pipeline::class,
        ];
    }
}
