<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

final class CompensateJobB
{
    use InteractsWithPipeline;

    /** @var array<int, string> */
    public static array $executed = [];

    /**
     * Optional callback fired inside handle() so integration tests can
     * capture compensation-chain ordering via a shared log (mirrors the
     * `$onHandle` hook on CompensateJobA). Reset to null between tests
     * that register it.
     *
     * @var Closure|null
     */
    public static ?Closure $onHandle = null;

    /**
     * Append this compensation class to the shared $executed log and fire
     * the optional $onHandle observer so tests can record cross-class
     * ordering inside the compensation chain.
     *
     * @return void
     */
    public function handle(): void
    {
        self::$executed[] = self::class;

        if (self::$onHandle !== null) {
            (self::$onHandle)();
        }
    }
}
