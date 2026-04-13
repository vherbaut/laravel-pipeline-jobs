<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

final class FailingCompensationJob
{
    /** @var array<int, string> */
    public static array $executed = [];

    /**
     * Record this compensation ran, then throw to simulate a failing compensation step.
     *
     * @return void
     *
     * @throws \RuntimeException Always, to exercise compensation failure paths in tests.
     */
    public function handle(): void
    {
        self::$executed[] = self::class;

        throw new \RuntimeException('Compensation job failed intentionally');
    }
}
