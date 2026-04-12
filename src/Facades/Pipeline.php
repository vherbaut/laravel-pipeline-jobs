<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Facades;

use Illuminate\Support\Facades\Facade;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\Testing\PipelineFake;

/**
 * Facade proxy for JobPipeline providing a familiar Laravel entry point for building pipelines.
 *
 * @see JobPipeline
 *
 * @method static \Vherbaut\LaravelPipelineJobs\PipelineBuilder|\Vherbaut\LaravelPipelineJobs\Testing\FakePipelineBuilder make(array<int, class-string> $jobs = [])
 * @method static void listen(class-string $eventClass, array<int, class-string> $jobs, \Closure|null $send = null)
 * @method static \Vherbaut\LaravelPipelineJobs\Testing\PipelineFake recording()
 * @method static void assertPipelineRan(\Closure|null $callback = null)
 * @method static void assertPipelineRanWith(array<int, string> $expectedJobs)
 * @method static void assertNoPipelinesRan()
 * @method static void assertPipelineRanTimes(int $count)
 * @method static void assertStepExecuted(string $jobClass, int|null $pipelineIndex = null)
 * @method static void assertStepNotExecuted(string $jobClass, int|null $pipelineIndex = null)
 * @method static void assertStepsExecutedInOrder(array<int, string> $expectedJobs, int|null $pipelineIndex = null)
 * @method static void assertContextHas(string $property, mixed $expected, int|null $pipelineIndex = null)
 * @method static void assertContext(\Closure $callback, int|null $pipelineIndex = null)
 * @method static \Vherbaut\LaravelPipelineJobs\Context\PipelineContext|null getRecordedContext(int|null $pipelineIndex = null)
 * @method static \Vherbaut\LaravelPipelineJobs\Context\PipelineContext|null getContextAfterStep(string $jobClass, int|null $pipelineIndex = null)
 */
class Pipeline extends Facade
{
    /**
     * Replace the bound instance with a PipelineFake for testing.
     *
     * After calling this method, all Pipeline facade calls resolve to
     * the PipelineFake, and no pipeline jobs will actually execute.
     * Follows the Bus::fake() / Queue::fake() pattern.
     *
     * @return PipelineFake The PipelineFake instance now bound in the container.
     */
    public static function fake(): PipelineFake
    {
        $fake = new PipelineFake;

        static::swap($fake);

        return $fake;
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return JobPipeline::class;
    }
}
