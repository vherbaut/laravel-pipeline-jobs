<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts;

use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class NonSerializableContext extends PipelineContext
{
    public ?Closure $callback = null;
}
