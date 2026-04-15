<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use RuntimeException;
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

final class FailingThenSucceedingJob
{
    use InteractsWithPipeline;

    /**
     * Number of attempts that must fail before the job succeeds.
     *
     * @var int
     */
    public static int $attemptsBeforeSuccess = 1;

    /**
     * Total number of times handle() has been invoked across all attempts.
     *
     * @var int
     */
    public static int $invocationCount = 0;

    /**
     * Wall-clock timestamps of each handle() invocation, recorded via microtime(true).
     *
     * @var array<int, float>
     */
    public static array $invocationTimestamps = [];

    /**
     * Reset the static counters between test cases.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$attemptsBeforeSuccess = 1;
        self::$invocationCount = 0;
        self::$invocationTimestamps = [];
    }

    /**
     * Throw on the first $attemptsBeforeSuccess invocations, then succeed.
     *
     * @return void
     *
     * @throws RuntimeException On the first $attemptsBeforeSuccess invocations.
     */
    public function handle(): void
    {
        self::$invocationCount++;
        self::$invocationTimestamps[] = microtime(true);

        if (self::$invocationCount <= self::$attemptsBeforeSuccess) {
            throw new RuntimeException('FailingThenSucceedingJob failure on attempt '.self::$invocationCount);
        }
    }
}
