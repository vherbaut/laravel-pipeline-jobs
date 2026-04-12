<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Events;

/**
 * Fixture event dispatched by the Pipeline::listen() independence tests.
 *
 * Second event class used alongside TestOrderPlacedEvent to prove that
 * two Pipeline::listen() registrations for different event classes do
 * not cross-talk through the shared event dispatcher.
 */
final class TestOrderShippedEvent
{
    /**
     * Create a new test event carrying an order identifier.
     *
     * @param string $orderId Arbitrary scalar payload used to verify closure reception; not structurally required.
     * @return void
     */
    public function __construct(public readonly string $orderId) {}
}
