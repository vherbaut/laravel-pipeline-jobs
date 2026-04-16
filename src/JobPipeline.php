<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs;

use Closure;
use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;

/**
 * Main entry point for building and dispatching job pipelines.
 *
 * This class provides the static API for creating pipeline builders
 * from arrays of job class names.
 */
final class JobPipeline
{
    /**
     * Create a new pipeline builder from an array of job class names.
     *
     * @param array<int, string> $jobs Fully qualified job class names to add as pipeline steps.
     */
    public static function make(array $jobs = []): PipelineBuilder
    {
        return new PipelineBuilder($jobs);
    }

    /**
     * Create a pending dispatch that auto-executes when the returned wrapper is destroyed.
     *
     * Alternative execution verb matching Laravel's Bus::dispatch() familiarity.
     * The returned PendingPipelineDispatch proxies every fluent method on
     * PipelineBuilder; calling run() is unnecessary. The wrapper's __destruct()
     * invokes it automatically when the object goes out of scope.
     *
     * For synchronous pipelines where the final PipelineContext or a ->return()
     * callback result is needed, prefer Pipeline::make($jobs)->send($ctx)->run()
     * which returns the value directly. The dispatch() verb eagerly executes on
     * destruction and discards the return value; any exception raised during
     * execution still propagates out of the destructor call site (AC #11).
     *
     * @param array<int, class-string|StepDefinition> $jobs Fully qualified job class names or pre-built step definitions.
     * @return PendingPipelineDispatch A wrapper that auto-runs on destruction.
     *
     * @throws InvalidPipelineDefinition When a job entry is neither a class-string nor a StepDefinition. Surfaces at construction time.
     */
    public static function dispatch(array $jobs = []): PendingPipelineDispatch
    {
        return new PendingPipelineDispatch(new PipelineBuilder($jobs));
    }

    /**
     * Build a parallel step group for inline use inside a pipeline definition.
     *
     * Usage: `Pipeline::make([A::class, JobPipeline::parallel([B::class, C::class]), D::class])`.
     * The returned ParallelStepGroup slots into the pipeline's $steps array
     * as a single logical position; at execution time, sub-steps fan out to
     * concurrent workers via Bus::batch() under queued mode or run
     * sequentially in declaration order under sync mode.
     *
     * This is a VALUE CONSTRUCTOR, not an execution verb: the resulting
     * group is consumed by PipelineBuilder / JobPipeline::make() and does
     * not itself trigger dispatch. For fluent-builder usage prefer
     * PipelineBuilder::parallel() (same arguments, identical semantics).
     *
     * @param array<int, class-string|StepDefinition> $jobs Sub-step class-strings or pre-built StepDefinition instances (at least one).
     *
     * @return ParallelStepGroup A value object grouping the sub-steps for parallel execution.
     *
     * @throws InvalidPipelineDefinition When $jobs is empty or contains an unsupported item type.
     */
    public static function parallel(array $jobs): ParallelStepGroup
    {
        return ParallelStepGroup::fromArray($jobs);
    }

    /**
     * Register a pipeline as a Laravel event listener in a single call.
     *
     * Equivalent to: make($jobs)->send($send)->toListener() + Event::listen($eventClass, $listener).
     * The optional $send closure receives the dispatched event instance and
     * must return a PipelineContext (or null). When $send is omitted, the
     * pipeline runs with a null context, mirroring PipelineBuilder::run()
     * without send() semantics.
     *
     * The underlying toListener() closure is captured eagerly: the pipeline
     * definition and send resolver are snapshotted at listen() time, so
     * subsequent modifications to any intermediate builder have no effect.
     *
     * @param class-string $eventClass Fully qualified event class the pipeline listens to.
     * @param array<int, class-string> $jobs Fully qualified job class names executed in declared order.
     * @param Closure|null $send Optional context resolver. Receives the event instance and returns a PipelineContext or null.
     * @return void
     *
     * @throws InvalidPipelineDefinition When $jobs is empty (fails fast at call time).
     */
    public static function listen(string $eventClass, array $jobs, ?Closure $send = null): void
    {
        $builder = self::make($jobs);

        if ($send !== null) {
            $builder->send($send);
        }

        Event::listen($eventClass, $builder->toListener());
    }
}
