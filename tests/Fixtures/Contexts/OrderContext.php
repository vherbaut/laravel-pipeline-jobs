<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Models\TestUser;

class OrderContext extends PipelineContext
{
    public string $orderId = '';

    public ?TestUser $user = null;
}
