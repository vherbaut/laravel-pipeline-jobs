# Conditional Steps

Some steps should only run under specific runtime conditions (a feature flag, a context value set by an earlier step, a user preference). The builder exposes `when()` and `unless()` for this.

```php
JobPipeline::make()
    ->step(ValidateOrder::class)
    ->when(
        fn (OrderContext $ctx) => $ctx->order->requiresApproval,
        NotifyManager::class,
    )
    ->step(ChargeCustomer::class)
    ->unless(
        fn (OrderContext $ctx) => $ctx->order->isDigital,
        ShipPackage::class,
    )
    ->send(new OrderContext(order: $order))
    ->run();
```

- `when(Closure $condition, string $jobClass)` appends a step that runs only when the closure returns truthy.
- `unless(Closure $condition, string $jobClass)` is the inverse. The step runs only when the closure returns falsy.

## Runtime Evaluation

Conditions are evaluated against the live `PipelineContext` immediately before the step would execute, in both synchronous and queued modes. Earlier steps can mutate the context and later conditions see the updated state.

```php
JobPipeline::make()
    ->step(LoadOrder::class) // populates $ctx->order
    ->when(
        fn (OrderContext $ctx) => $ctx->order->status === 'pending',
        SendReminderEmail::class, // only runs when the loaded order is pending
    )
    ->send(new OrderContext(orderId: $id))
    ->run();
```

## Queued Pipelines

Condition closures must be serializable because they travel with the manifest. The builder wraps them with `SerializableClosure` automatically, so any variables captured through `use` must be serializable too. Avoid capturing resources, anonymous classes, or live database handles inside a condition. When the predicate depends on external state, load that state in a preceding step and read it from the context instead.

## Composing with Compensation

A conditional step can still register a compensation job.

```php
JobPipeline::make()
    ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
    ->when(
        fn (OrderContext $ctx) => $ctx->order->total > 100,
        ChargeCustomer::class,
    )->compensateWith(RefundCustomer::class)
    ->send(new OrderContext(order: $order))
    ->run();
```

If the conditional step is skipped (the closure returned falsy), its compensation never runs, even if a later step fails. Compensation only applies to steps that actually executed.

## Interaction with Lifecycle Hooks

[Per-step lifecycle hooks](lifecycle-hooks.md) do not fire for steps skipped via `when()` / `unless()`. The skip check precedes all hook firing.
