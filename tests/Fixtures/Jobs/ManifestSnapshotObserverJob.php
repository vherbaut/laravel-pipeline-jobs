<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Throwable;
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

/**
 * Captures primitive snapshots of the injected PipelineManifest at handle-time.
 *
 * Unlike ManifestObserverJob, which stores the manifest reference, this fixture
 * copies the fields needed to assert post-run state under SkipAndContinue.
 * Required because the sync and queued executors mutate the manifest AFTER
 * each step's handle() returns (markStepCompleted, advanceStep, AC #6 clear),
 * so a reference snapshot taken mid-handle is invalidated by the mutations
 * that follow observer's own success.
 */
final class ManifestSnapshotObserverJob
{
    use InteractsWithPipeline;

    public static ?string $failedStepClass = null;

    public static ?int $failedStepIndex = null;

    public static ?Throwable $failureException = null;

    /** @var array<int, string> */
    public static array $completedSteps = [];

    public static bool $observed = false;

    /**
     * Snapshot the manifest's failure-context fields and completedSteps for later assertion.
     *
     * @return void
     */
    public function handle(): void
    {
        self::$observed = true;

        if ($this->pipelineManifest === null) {
            return;
        }

        self::$failedStepClass = $this->pipelineManifest->failedStepClass;
        self::$failedStepIndex = $this->pipelineManifest->failedStepIndex;
        self::$failureException = $this->pipelineManifest->failureException;
        self::$completedSteps = $this->pipelineManifest->completedSteps;
    }

    /**
     * Reset all observer statics between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$failedStepClass = null;
        self::$failedStepIndex = null;
        self::$failureException = null;
        self::$completedSteps = [];
        self::$observed = false;
    }
}
