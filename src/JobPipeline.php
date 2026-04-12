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
