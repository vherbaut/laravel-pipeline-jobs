# Event Listener Bridge

One of the most common Laravel patterns is dispatching jobs in response to events. Normally this requires creating a dedicated listener class for each event and job combination. This package eliminates that boilerplate.

## One Liner Registration

Register a pipeline as an event listener in your service provider.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        JobPipeline::listen(
            OrderPlaced::class,
            [ProcessOrder::class, SendReceipt::class, UpdateAnalytics::class],
            fn (OrderPlaced $event) => new OrderContext(order: $event->order),
        );
    }
}
```

The third argument is a closure that receives the event and returns a `PipelineContext`. That closure is how you bridge event data into the pipeline.

## Alternative Syntax with `toListener()`

When you need more control (custom builder configuration, conditional steps, compensation, lifecycle hooks), use `toListener()`.

```php
$listener = JobPipeline::make([
    ProcessOrder::class,
    SendReceipt::class,
])
    ->send(fn (OrderPlaced $event) => new OrderContext(order: $event->order))
    ->toListener();

Event::listen(OrderPlaced::class, $listener);
```

Both approaches are equivalent. The closure form (`send(fn ($event) => ...)`) is preferred because it defers context creation to the moment the event actually fires, rather than creating it eagerly.

## Capturing the Builder State

`toListener()` captures the builder state eagerly at the time it is called. Subsequent mutations to the builder do not affect the already-returned listener closure. If you need to register multiple listener variants, call `toListener()` once per variant on a freshly configured builder.
