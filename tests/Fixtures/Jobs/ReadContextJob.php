<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

final class ReadContextJob
{
    use InteractsWithPipeline;

    /** @var string|null */
    public static ?string $readName = null;

    /**
     * Read the injected SimpleContext's $name into self::$readName for test observation.
     *
     * @return void
     */
    public function handle(): void
    {
        $context = $this->pipelineContext();

        if ($context instanceof SimpleContext) {
            self::$readName = $context->name;
        }
    }
}
