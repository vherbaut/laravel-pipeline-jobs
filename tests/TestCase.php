<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vherbaut\LaravelPipelineJobs\PipelineServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PipelineServiceProvider::class,
        ];
    }
}
