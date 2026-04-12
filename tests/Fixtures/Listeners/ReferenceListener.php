<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Listeners;

use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Events\TestOrderPlacedEvent;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ReadContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobA;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\TrackExecutionJobB;

/**
 * Hand-written reference listener used by the NFR3 parity test.
 *
 * Performs the same manifest build + SyncExecutor invocation that
 * PipelineBuilder::toListener() wraps, so the parity test can diff
 * execution order and final context state across both code paths.
 */
final class ReferenceListener
{
    /**
     * Handle the test event by running the same pipeline toListener() would wrap.
     *
     * @param TestOrderPlacedEvent $event The dispatched test event.
     * @return void
     */
    public function handle(TestOrderPlacedEvent $event): void
    {
        $context = new SimpleContext;
        $context->name = $event->orderId;

        JobPipeline::make([
            TrackExecutionJobA::class,
            TrackExecutionJobB::class,
            ReadContextJob::class,
        ])
            ->send($context)
            ->run();
    }
}
