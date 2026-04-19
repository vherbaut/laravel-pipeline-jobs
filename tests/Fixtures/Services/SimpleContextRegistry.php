<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Services;

/**
 * Trivial registry service for the Story 9.4 Action-with-DI fixture.
 *
 * Holds a list of recorded values; used to assert that an Action-shape step
 * received both a container-resolved dependency AND the named-bound context.
 */
final class SimpleContextRegistry
{
    /** @var array<int, ?string> */
    private array $observed = [];

    /**
     * Record a value (typically the observed PipelineContext name).
     *
     * @param string|null $value The value to record.
     * @return void
     */
    public function record(?string $value): void
    {
        $this->observed[] = $value;
    }

    /**
     * Return all recorded values in insertion order.
     *
     * @return array<int, ?string> The observed values.
     */
    public function all(): array
    {
        return $this->observed;
    }
}
