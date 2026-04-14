<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs;

use Closure;
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

final class CompensateJobA
{
    use InteractsWithPipeline;

    /** @var array<int, string> */
    public static array $executed = [];

    /**
     * Captures SimpleContext::$name observed through the trait accessor.
     *
     * Resets to null between tests via TraitAdoptionTest's beforeEach so
     * compensation scenarios can assert AC #9 (context mutations from
     * forward steps remain visible in compensation jobs that use the
     * trait).
     *
     * @var string|null
     */
    public static ?string $observedName = null;

    /**
     * Optional callback fired inside handle() so tests can observe the
     * compensation invocation moment (e.g., to assert hook-vs-compensation
     * ordering). Reset to null between tests that register it.
     *
     * @var Closure|null
     */
    public static ?Closure $onHandle = null;

    /**
     * Append this compensation class to the shared $executed log and
     * capture the pipeline context's $name for compensation-path trait
     * assertions.
     *
     * @return void
     */
    public function handle(): void
    {
        self::$executed[] = self::class;

        $context = $this->pipelineContext();

        if ($context instanceof SimpleContext) {
            self::$observedName = $context->name;
        }

        if (self::$onHandle !== null) {
            (self::$onHandle)();
        }
    }
}
