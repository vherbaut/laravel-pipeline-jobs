# Dispatch Verb

`Pipeline::dispatch([...])` is an alternative execution verb to `Pipeline::make([...])->run()`. It matches Laravel's familiar `Bus::dispatch($job)` idiom by auto-executing the pipeline when the returned wrapper goes out of scope, so you do not need to remember a terminal `->run()` call.

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::dispatch([
    ValidateOrder::class,
    ChargeCustomer::class,
    SendConfirmation::class,
])->send(new OrderContext(order: $order));
```

The temporary `PendingPipelineDispatch` wrapper is destroyed at the end of the statement, and its destructor invokes the underlying builder's `run()` method exactly once.

## Table of Contents

- [When to Use Dispatch](#when-to-use-dispatch)
- [Fluent Surface](#fluent-surface)
- [Destruct Timing](#destruct-timing)
- [Cancelling a Pending Dispatch](#cancelling-a-pending-dispatch)
- [Dispatch vs Make Decision Matrix](#dispatch-vs-make-decision-matrix)
- [Testing with Pipeline::fake()](#testing-with-pipelinefake)
- [Exception Propagation](#exception-propagation)
- [Known Hazards](#known-hazards)

## When to Use Dispatch

Use `Pipeline::dispatch()` when:

- You want fire-and-forget execution with no need to capture a return value.
- You prefer the `Bus::dispatch($job)` style over the explicit `->run()` terminator.
- You are dispatching a pipeline to the queue in a controller action or service method.

Stay on `Pipeline::make()->run()` when:

- You need the final `PipelineContext` as a return value.
- You registered a `->return(Closure)` callback and want its result.
- You need deterministic execution timing without relying on the destructor.

## Fluent Surface

Every non-terminal fluent method on `PipelineBuilder` is available on the dispatch wrapper. The following terminal or definition methods are intentionally not proxied:

- `run()`: the dispatch verb triggers execution via the destructor.
- `toListener()`: use `Pipeline::listen()` for event listener registration.
- `build()`: internal definition verb.
- `return()`: dispatch discards the return value by design.
- `getContext()`: context inspection belongs on a retained builder.

All per-step configuration methods are available: `onQueue`, `onConnection`, `sync`, `retry`, `backoff`, `timeout`, plus pipeline-level defaults. See [Per-Step Configuration](per-step-configuration.md).

```php
Pipeline::dispatch([
    ValidateOrder::class,
    ChargeCustomer::class,
])
    ->defaultQueue('orders')
    ->defaultRetry(2)
    ->step(LogSuccess::class)->onQueue('logs')  // continue adding steps fluently
    ->send(new OrderContext(order: $order))
    ->shouldBeQueued()
    ->onFailure(FailStrategy::StopAndCompensate);
```

## Destruct Timing

PHP's destructor fires when the wrapper's reference count reaches zero. The exact timing depends on how the expression is used.

**Bare statement (recommended).** The temporary wrapper is destroyed at the end of the statement, before the next statement begins. This is the idiomatic form and matches `Bus::dispatch($job);`.

```php
Pipeline::dispatch([A::class])->send($ctx);
// Destructor fires here. Execution is strictly-before the next statement.
doOtherWork();
```

**Variable assignment (discouraged).** Execution defers until the variable goes out of scope.

```php
$pending = Pipeline::dispatch([A::class])->send($ctx);
doOtherWork();
// Destructor has NOT fired yet.
// End of enclosing scope (function, method, closure): destructor fires.
```

**Explicit unset.** Forces the destructor to fire immediately.

```php
$pending = Pipeline::dispatch([A::class])->send($ctx);
unset($pending);
// Destructor fires here.
```

If you capture the wrapper to a variable, prefer `Pipeline::make()->run()` instead for deterministic timing.

## Cancelling a Pending Dispatch

Call `cancel()` to opt a wrapper out of its auto-run contract. The destructor short-circuits and the underlying builder is never executed.

```php
$pending = Pipeline::dispatch([ChargeCustomer::class])->send($ctx);

if ($order->wasRefunded()) {
    $pending->cancel();
    return;
}

// Destructor fires at end of scope and runs the pipeline normally.
```

`cancel()` is idempotent and safe to call multiple times. Once called, the wrapper becomes inert.

## Dispatch vs Make Decision Matrix

| Use case | Recommended verb | Rationale |
|----------|------------------|-----------|
| Fire-and-forget sync execution | `Pipeline::dispatch([...])->send(...)` | Matches `Bus::dispatch($job)` familiarity |
| Fire-and-forget queued execution | `Pipeline::dispatch([...])->send(...)->shouldBeQueued()` | Same idiom for queued mode |
| Need the final `PipelineContext` | `Pipeline::make([...])->send(...)->run()` | `dispatch()` discards the return value |
| Need a `->return()` callback result | `Pipeline::make([...])->send(...)->return($cb)->run()` | `dispatch()` has no `return()` proxy |
| Event listener registration | `Pipeline::listen($eventClass, [...])` | Unchanged by the dispatch verb |
| Testing with `Pipeline::fake()` | Either verb | Both are recorded identically by the fake |

## Testing with Pipeline::fake()

Under `Pipeline::fake()`, `Pipeline::dispatch()` records the pipeline exactly like `Pipeline::make()->run()`. All fake assertion helpers (`assertPipelineRan`, `assertStepExecuted`, `assertContextHas`, etc.) work identically regardless of which verb produced the dispatch.

```php
Pipeline::fake();

Pipeline::dispatch([A::class, B::class])->send(new OrderContext(order: $order));

Pipeline::assertPipelineRan();
Pipeline::assertPipelineRanWith([A::class, B::class]);
```

Recording mode (`Pipeline::fake()->recording()`) also works transparently with the dispatch verb. See [Testing](testing.md).

## Exception Propagation

Exceptions thrown from the wrapped builder's `run()` propagate verbatim out of the destructor. PHP 7+ permits destructor exceptions during normal execution.

```php
try {
    Pipeline::dispatch([ValidateOrder::class])->send($ctx);
} catch (StepExecutionFailed $e) {
    // Handle the failure normally.
}
```

If a fluent method (for example `->send()` with an invalid closure) throws before scope end, the destructor still fires `run()` on the partially-configured builder. This can raise a second exception that obscures the first. Callers who need deterministic exception visibility should prefer `Pipeline::make()->run()` or `cancel()` the wrapper in a catch block before scope end.

## Known Hazards

The destructor-driven execution model has well-known limitations in non-standard PHP runtime contexts.

**`exit()` and `die()`.** PHP does not guarantee destructor invocation during process exit for assigned variables. A pipeline assigned to `$pending` and followed by an `exit(302)` call may be silently dropped. Prefer bare-statement form or `Pipeline::make()->run()` in handlers that can exit early.

**`pcntl_fork()` (Laravel Octane, Horizon, long-running daemons).** If a wrapper is alive at fork time, both parent and child run its destructor, duplicating the dispatch. Prefer `Pipeline::make()->run()` in forking contexts, or `cancel()` the wrapper before fork.

**PHP shutdown with static or container-held wrappers.** The destructor may fire after Laravel's container has been torn down, causing `BindingResolutionException` inside a destructor frame (fatal, uncatchable). Do not attach pending dispatches to static properties or request-lifetime singletons.

These hazards mirror Laravel's own `PendingDispatch` behavior. For deterministic execution, `Pipeline::make()->run()` remains available and preferable in every case that falls outside the bare-statement idiom.
