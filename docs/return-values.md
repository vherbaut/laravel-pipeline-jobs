# Return Values

By default, `->run()` returns the full `PipelineContext` after synchronous execution. When you only care about a single field (a total, an invoice ID, a computed result), the `->return()` method lets you declare a closure that transforms the final context into the value you actually want.

```php
$total = JobPipeline::make([
    CreateOrder::class,
    ApplyDiscount::class,
    CalculateTotal::class,
])
    ->send(new OrderContext(items: $items))
    ->return(fn (OrderContext $ctx) => $ctx->total)
    ->run();

// $total is the scalar computed by CalculateTotal. No manual dereferencing.
```

## Behaviour Notes

- **Sync only.** The closure runs exclusively in synchronous mode. Queued runs always return `null` because execution is deferred to workers and the closure is never invoked.
- **Null argument.** When no context was sent via `->send()`, the closure is still called with `null` as its argument. Your closure is responsible for handling the null case.
- **Last write wins.** Calling `->return()` multiple times silently overrides the previous closure. Only the most recent registration is applied.
- **Exceptions propagate verbatim.** If your return closure throws, the exception bubbles out of `->run()` unchanged. It is NOT wrapped in `StepExecutionFailed` because the closure runs after the executor, not as a step.

## Without and With `->return()`

Without:

```php
$context = JobPipeline::make([CreateOrder::class, CalculateTotal::class])
    ->send(new OrderContext(items: $items))
    ->run();

return $context->total; // Manual dereferencing, return type widens to ?PipelineContext
```

With:

```php
return JobPipeline::make([CreateOrder::class, CalculateTotal::class])
    ->send(new OrderContext(items: $items))
    ->return(fn (OrderContext $ctx) => $ctx->total)
    ->run();
```
