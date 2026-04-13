<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Concerns;

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

/**
 * Adoption trait that lets existing Laravel jobs participate in pipelines
 * without being rewritten.
 *
 * Drop-in usage:
 *
 * ```php
 * use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
 *
 * final class SendWelcomeEmail
 * {
 *     use InteractsWithPipeline;
 *
 *     public function handle(): void
 *     {
 *         if ($this->hasPipelineContext()) {
 *             $user = $this->pipelineContext()->user;
 *             // ... use the pipeline-supplied user
 *             return;
 *         }
 *
 *         // Standalone dispatch mode: run as before.
 *     }
 * }
 * ```
 *
 * Contract with executors: `SyncExecutor`, `PipelineStepJob`, and
 * `RecordingExecutor` inject the current `PipelineManifest` onto every job
 * they run via `property_exists($job, 'pipelineManifest')` followed by
 * `ReflectionProperty::setValue()`. Declaring the property on a trait
 * satisfies that check without any change to the executors (FR41).
 *
 * When a job is dispatched outside of a pipeline (for example
 * `Bus::dispatch(new MyJob)`), no executor touches the property, so it
 * stays at its `null` default and both accessors short-circuit to their
 * "not in a pipeline" values (FR42).
 *
 * `pipelineContext()` returns the exact same `PipelineContext` instance
 * the manifest carries. Mutations made through the accessor are visible
 * to subsequent pipeline steps (FR43).
 */
trait InteractsWithPipeline
{
    /**
     * The pipeline manifest injected by the executor at step execution time.
     *
     * @var PipelineManifest|null
     */
    protected ?PipelineManifest $pipelineManifest = null;

    /**
     * Return the live pipeline context for the current pipeline run.
     *
     * Returns `null` when this job runs outside a pipeline or when the
     * pipeline was dispatched without a context. When non-null, the
     * returned instance is the same reference carried by the manifest,
     * so mutations propagate to subsequent steps.
     *
     * @return PipelineContext|null The pipeline context when running inside a pipeline with a context, null otherwise.
     */
    public function pipelineContext(): ?PipelineContext
    {
        return $this->pipelineManifest?->context;
    }

    /**
     * Determine whether a pipeline context is currently available.
     *
     * Returns `true` only when this job runs inside a pipeline AND the
     * pipeline carries a non-null context. Returns `false` for
     * standalone dispatch and for pipelines dispatched without
     * `->send(...)`.
     *
     * @return bool True when a non-null PipelineContext is available, false otherwise.
     */
    public function hasPipelineContext(): bool
    {
        return $this->pipelineManifest?->context !== null;
    }
}
