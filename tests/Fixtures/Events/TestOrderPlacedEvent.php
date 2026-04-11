<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Events;

/**
 * Fixture event dispatched by the listener bridge tests.
 *
 * Holds a single scalar payload so the ->send() closure has something
 * meaningful to copy into the context, proving that the closure received
 * the event instance end-to-end.
 */
final class TestOrderPlacedEvent
{
    /**
     * Create a new test event carrying an order identifier.
     *
     * @param string $orderId Arbitrary scalar payload used to assert the closure received the event.
     * @return void
     */
    public function __construct(public readonly string $orderId) {}
}
