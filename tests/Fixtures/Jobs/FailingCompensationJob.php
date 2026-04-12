<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

final class FailingCompensationJob
{
    /** @var array<int, string> */
    public static array $executed = [];

    public function handle(): void
    {
        self::$executed[] = self::class;

        throw new \RuntimeException('Compensation job failed intentionally');
    }
}
