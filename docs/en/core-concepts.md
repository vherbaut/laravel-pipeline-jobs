# Core Concepts

Three abstractions sit at the heart of the package: the typed `PipelineContext`, the fluent `PipelineBuilder`, and the two execution modes.

## Table of Contents

- [Pipeline Context](#pipeline-context)
- [Pipeline Builder](#pipeline-builder)
- [Execution Modes](#execution-modes)

## Pipeline Context

The `PipelineContext` class is the foundation of data flow. It is a simple DTO (Data Transfer Object) that travels through each step, accumulating state along the way.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class MyContext extends PipelineContext
{
    public ?Model $result = null;
    public array $metadata = [];
    public string $status = 'pending';
}
```

Key characteristics:

- **Typed properties.** Use PHP's type system to define exactly what data flows through your pipeline. Each step knows what it can read and write.
- **Eloquent model support.** The base class uses Laravel's `SerializesModels` trait, so Eloquent models are properly serialized when pipelines are queued.
- **Serialization validation.** Before dispatching a queued pipeline, the context is validated to ensure all properties are serializable. Closures, resources, and anonymous classes are rejected immediately with a clear error message, rather than failing silently in the queue worker.

## Pipeline Builder

The builder provides a fluent API for constructing pipelines. Two equivalent syntaxes are available.

**Array API** (concise, great for simple pipelines):

```php
JobPipeline::make([
    StepA::class,
    StepB::class,
    StepC::class,
]);
```

**Fluent API** (allows per step configuration like compensation):

```php
JobPipeline::make()
    ->step(StepA::class)
    ->step(StepB::class)->compensateWith(UndoStepB::class)
    ->step(StepC::class)->compensateWith(UndoStepC::class);
```

Both produce the same immutable `PipelineDefinition`. Choose whichever reads best for your use case.

The fluent API is required when you need to attach metadata to an individual step, for example `compensateWith()` for [saga compensation](saga-compensation.md) or conditional branches via [`when()` / `unless()`](conditional-steps.md).

## Execution Modes

Pipelines support two execution modes.

### Synchronous (default)

Steps execute one after another in the current process. The final context is returned directly.

```php
$result = JobPipeline::make([...])
    ->send($context)
    ->run(); // Returns PipelineContext
```

### Queued

Steps are dispatched to Laravel's queue system. Each step is wrapped in an internal job that, upon completion, dispatches the next step. Steps run on potentially different workers, with the full pipeline state serialized in each job payload.

```php
JobPipeline::make([...])
    ->send($context)
    ->shouldBeQueued()
    ->run(); // Returns null (execution is async)
```

The queued executor validates context serialization **before** dispatching. If your context contains a closure or a resource, you will get an immediate `ContextSerializationFailed` exception rather than a mysterious queue failure minutes later.

See [Queued Pipelines](queued-pipelines.md) for a deeper dive on queue semantics, retries, and worker affinity.
