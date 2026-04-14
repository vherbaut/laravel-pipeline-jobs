# Queued Pipelines

To dispatch a pipeline to the queue, add `shouldBeQueued()`.

```php
JobPipeline::make([
    ProcessVideo::class,
    GenerateThumbnails::class,
    NotifyUser::class,
])
    ->send(new VideoContext(video: $video))
    ->shouldBeQueued()
    ->run();
```

## How It Works Under the Hood

1. The context is validated for serializability (fail fast).
2. The first step is wrapped in a `PipelineStepJob` and dispatched to the queue.
3. When the first step completes, the next `PipelineStepJob` is dispatched automatically.
4. This continues until all steps have executed.

Each queued job carries the complete pipeline manifest (context, step list, progress). Any worker can pick up any step, and there is no external state to manage.

## Retries

Queued pipeline steps use `tries = 1` by default. This prevents the re execution of already completed steps after a worker crash. If you need retries, implement retry logic within each individual job.

## Serialization

Everything that travels with the pipeline must be serializable.

- **Context properties.** Validated before dispatch. Closures, resources, and anonymous classes trigger an immediate `ContextSerializationFailed` exception.
- **Condition closures** (from `when()` / `unless()`). Wrapped in `SerializableClosure`. Variables captured via `use` must also be serializable.
- **Lifecycle hook and callback closures.** Same constraint. See [Lifecycle Hooks](lifecycle-hooks.md#queued-mode-notes).

When the predicate or the callback needs external state, load that state in a preceding step and read it from the context instead of capturing it through `use`.

## Return Value in Queued Mode

Queued runs always return `null` because execution is deferred to workers. Any `->return()` closure is ignored in queued mode. See [Return Values](return-values.md) for details.

## Compensation in Queued Mode

Under `FailStrategy::StopAndCompensate`, compensation jobs are dispatched as a `Bus::chain` on failure. The chain halts on the first failing compensation (documented divergence from sync best effort). See [Saga Compensation](saga-compensation.md).
