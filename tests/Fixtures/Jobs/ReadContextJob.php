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

    /** @var int|null */
    public static ?int $readCount = null;

    /**
     * Read the injected SimpleContext's $name and $count into the matching
     * self:: statics so integration tests can assert the terminal step
     * observed the merged context from a preceding parallel group.
     *
     * @return void
     */
    public function handle(): void
    {
        $context = $this->pipelineContext();

        if ($context instanceof SimpleContext) {
            self::$readName = $context->name;
            self::$readCount = $context->count;
        }
    }
}
