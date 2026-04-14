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
     * Ordered record of pipeline-level callback firings.
     *
     * Captures the sequence of `onSuccess`, `onFailure`, and `onComplete`
     * invocations across sync, queued, and recording executors. Tests rely
     * on this array both for firing-count assertions (count) and for
     * ordering assertions (index) between Story 6.1 per-step hooks and
     * Story 6.2 pipeline-level callbacks.
     *
     * @var array<int, string>
     */
    public static array $fired = [];

    /**
     * Exception captured by the most recent `onFailure` callback invocation.
     *
     * Populated when a test registers an `onFailure` callback that stores the
     * second closure argument into this static. Cleared by reset().
     *
     * @var \Throwable|null
     */
    public static ?\Throwable $capturedException = null;

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
        self::$fired = [];
        self::$capturedException = null;
    }
}
