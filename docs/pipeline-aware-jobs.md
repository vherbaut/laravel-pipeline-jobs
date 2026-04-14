# Pipeline Aware Jobs

Every step in a pipeline needs to read or write the shared context. The package offers two equivalent ways to wire that up, and you can mix them freely across your codebase.

## Table of Contents

- [The InteractsWithPipeline Trait](#the-interactswithpipeline-trait)
- [Explicit Property](#explicit-property)
- [Dual Mode Jobs](#dual-mode-jobs)
- [How It Works Under the Hood](#how-it-works-under-the-hood)

## The InteractsWithPipeline Trait

Recommended for most cases. Drop the trait into any job class and you get two accessors for free.

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class SendWelcomeEmail
{
    use InteractsWithPipeline;

    public function handle(Mailer $mailer): void
    {
        $user = $this->pipelineContext()->user;

        $mailer->send(new WelcomeMail($user));
    }
}
```

| Accessor | Returns | Description |
|----------|---------|-------------|
| `pipelineContext()` | `?PipelineContext` | The live context when running inside a pipeline, `null` otherwise. |
| `hasPipelineContext()` | `bool` | Whether a non null context is currently available. |
| `failureContext()` | `?FailureContext` | Snapshot of the latest failure recorded on the manifest, or `null` when no failure was recorded or the job runs outside a pipeline. |

## Explicit Property

If you prefer a fully visible dependency (for example, to type hint a custom context subclass), declare the manifest as a public property.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

class SendWelcomeEmail
{
    public PipelineManifest $pipelineManifest;

    public function handle(Mailer $mailer): void
    {
        $user = $this->pipelineManifest->context->user;

        $mailer->send(new WelcomeMail($user));
    }
}
```

Both patterns produce identical runtime behaviour. The trait is less boilerplate, the explicit property is more discoverable. Pick whichever your team prefers.

## Dual Mode Jobs

The trait shines when you want the same job to run both standalone (via `Bus::dispatch`) and inside a pipeline. Use `hasPipelineContext()` to branch.

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class SyncProduct
{
    use InteractsWithPipeline;

    public function __construct(
        public readonly int $productId,
    ) {}

    public function handle(ProductSyncService $sync): void
    {
        if ($this->hasPipelineContext()) {
            // Pipeline mode: pull the product from the shared context.
            $sync->push($this->pipelineContext()->product);

            return;
        }

        // Standalone mode: load the product from storage.
        $sync->push(Product::findOrFail($this->productId));
    }
}
```

## How It Works Under the Hood

The pipeline executors (`SyncExecutor`, `PipelineStepJob`, `RecordingExecutor`) look for a `pipelineManifest` property on every step they run, via `property_exists()` and `ReflectionProperty::setValue()`. The trait declares that property for you.

When a job runs outside a pipeline, no executor touches the property, so it stays at its `null` default and both accessors return "not in a pipeline" values. The trait's `failureContext()` accessor behaves the same way: it reads the manifest's failure fields if they are populated, or returns `null` otherwise.
