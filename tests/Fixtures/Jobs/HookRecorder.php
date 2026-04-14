<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

/**
 * Static recording fixture used by hook tests that cross the queue boundary.
 *
 * Hook closures cannot capture test-local variables by reference across
 * SerializableClosure because the deserialized closure has no access to the
 * originating stack frame. Static-class state is the simplest mechanism
 * that survives the serialization round trip.
 */
final class HookRecorder
{
    /** @var array<int, string> */
    public static array $beforeEach = [];

    /** @var array<int, string> */
    public static array $afterEach = [];

    /** @var array<int, string> */
    public static array $onStepFailed = [];

    /** @var array<int, string> */
    public static array $order = [];

    /**
     * Reset all static recording arrays. Call from test beforeEach hooks.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$beforeEach = [];
        self::$afterEach = [];
        self::$onStepFailed = [];
        self::$order = [];
    }
}
