<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use RuntimeException;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

/**
 * Invokable Action fixture that fails on the first attempt and succeeds on the second.
 *
 * Used by Story 9.4 retry tests to verify that per-step `retry` config drives
 * the StepInvoker loop for Action-shape steps identically to handle()-shape
 * steps.
 */
final class ActionFlakyJob
{
    /** @var int Number of attempts seen so far across the pipeline run. */
    public static int $attempts = 0;

    /**
     * Fail on attempt #1, succeed (and mutate) on attempt #2+.
     *
     * @param PipelineContext|null $context Pipeline context bound by name via the dispatcher.
     * @return void
     */
    public function __invoke(?PipelineContext $context): void
    {
        self::$attempts++;

        if (self::$attempts < 2) {
            throw new RuntimeException('action-flaky');
        }

        if ($context instanceof SimpleContext) {
            $context->name = 'action-flaky-succeeded';
        }
    }
}
