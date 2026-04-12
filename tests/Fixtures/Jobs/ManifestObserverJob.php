<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

final class ManifestObserverJob
{
    public static ?PipelineManifest $observedManifest = null;

    protected ?PipelineManifest $pipelineManifest = null;

    public function handle(): void
    {
        self::$observedManifest = $this->pipelineManifest;
    }
}
