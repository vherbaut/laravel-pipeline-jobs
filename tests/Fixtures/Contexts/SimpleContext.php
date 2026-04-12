<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class SimpleContext extends PipelineContext
{
    public string $name = '';

    public int $count = 0;

    public bool $active = false;

    /** @var array<string, mixed> */
    public array $tags = [];
}
