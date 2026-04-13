<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

final class ManifestObserverJob
{
    public static ?PipelineManifest $observedManifest = null;

    protected ?PipelineManifest $pipelineManifest = null;

    /**
     * Capture the injected PipelineManifest into self::$observedManifest for test inspection.
     *
     * @return void
     */
    public function handle(): void
    {
        self::$observedManifest = $this->pipelineManifest;
    }
}
