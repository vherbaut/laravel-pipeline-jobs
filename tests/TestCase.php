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
