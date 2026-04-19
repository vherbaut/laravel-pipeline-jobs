# Per-Step Configuration

The per-step configuration API covers queue targeting, sync execution, retry, backoff, and timeout, plus pipeline-level defaults that apply to every step unless explicitly overridden.

All per-step configuration methods chain directly after `step()` (or `addStep()`) and apply to the last added step. Pipeline-level defaults can be declared anywhere on the builder (before or after steps). Per-step values always win over defaults.

## Table of Contents

- [Per-Step Queue and Connection](#per-step-queue-and-connection)
- [Per-Step Sync Override](#per-step-sync-override)
- [Per-Step Retry, Backoff, Timeout](#per-step-retry-backoff-timeout)
- [Pipeline-Level Defaults](#pipeline-level-defaults)
- [Precedence Rules](#precedence-rules)
- [Validation Rules](#validation-rules)
- [Complete Example](#complete-example)

## Per-Step Queue and Connection

Route each step to its own queue or connection with `onQueue()` and `onConnection()`. Both methods chain after a `step()` call and apply to the last added step only.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make()
    ->step(ValidateOrder::class)->onQueue('fast')
    ->step(ChargeCustomer::class)->onQueue('payments')->onConnection('redis-payments')
    ->step(SendConfirmation::class)->onQueue('notifications')
    ->send(new OrderContext(order: $order))
    ->shouldBeQueued()
    ->run();
```

Each step's `PipelineStepJob` wrapper is dispatched to the configured queue and connection. Steps without explicit queue or connection fall back to the pipeline-level defaults (see below), and if no defaults are declared, to Laravel's default queue and connection.

## Per-Step Sync Override

Force a specific step to run synchronously even when the pipeline is queued. Useful for steps that must block the caller (authentication, validation) while the rest of the pipeline runs async.

```php
JobPipeline::make()
    ->step(AuthenticateUser::class)->sync()
    ->step(LoadProfile::class)
    ->step(NotifyFriends::class)
    ->send(new UserContext(userId: $userId))
    ->shouldBeQueued()
    ->run();
```

The `AuthenticateUser` step executes inline through `SyncExecutor::execute()`, then the remaining steps enter the queue starting from `LoadProfile`. The pipeline context threads through the transition transparently.

When the pipeline itself is not queued, `sync()` is a no-op (all steps already run synchronously).

## Per-Step Retry, Backoff, Timeout

Attach per-step retry policies for flaky dependencies (remote APIs, rate-limited services).

```php
JobPipeline::make()
    ->step(CallExternalApi::class)
        ->retry(3)       // up to 3 retry attempts after the initial try
        ->backoff(10)    // wait 10s between attempts
        ->timeout(60)    // kill the attempt after 60s
    ->step(StoreResult::class)
    ->send(new ApiContext(endpoint: $endpoint))
    ->shouldBeQueued()
    ->run();
```

- `retry(int)`: number of retry attempts after the initial attempt. `0` means no retry. Must be non-negative.
- `backoff(int)`: seconds to sleep between attempts. `0` means no sleep. Must be non-negative.
- `timeout(int)`: maximum execution time in seconds for the queued wrapper. Must be greater than or equal to 1.

These values are threaded onto the step's `PipelineStepJob` wrapper's `$tries`, `$backoff`, and `$timeout` properties, and Laravel's queue worker enforces them. In sync mode, `retry` and `backoff` have no effect (the step runs inline once) and `timeout` is ignored because PHP's request has its own execution time limit.

## Pipeline-Level Defaults

Declare defaults that apply to every step without an explicit override.

```php
JobPipeline::make([
    SendEmail::class,
    SendSms::class,
    LogNotification::class,
])
    ->defaultQueue('notifications')
    ->defaultConnection('redis')
    ->defaultRetry(2)
    ->defaultBackoff(5)
    ->defaultTimeout(30)
    ->send(new NotificationContext(userId: $userId))
    ->shouldBeQueued()
    ->run();
```

All three notification steps inherit the queue name, connection, retry policy, and timeout. Any step that declares its own `onQueue()` or `retry()` takes precedence.

Defaults can be declared in any order relative to steps (before, between, after), because they apply at build time to every step that lacks an explicit value. The validation constraints mirror the per-step methods (non-empty strings, non-negative integers, positive timeout).

## Precedence Rules

Per-step values always win over pipeline-level defaults. The resolution order for any given step is:

1. Explicit per-step value (`->step(X)->retry(3)`).
2. Pipeline-level default (`->defaultRetry(1)`).
3. Package default (for retry and backoff, `null` meaning Laravel's own defaults apply; for queue and connection, Laravel's configured defaults).

Example:

```php
JobPipeline::make()
    ->defaultQueue('default-q')
    ->defaultRetry(1)
    ->step(StepA::class)                       // queue=default-q, retry=1
    ->step(StepB::class)->onQueue('priority')  // queue=priority,  retry=1
    ->step(StepC::class)->retry(5)             // queue=default-q, retry=5
    ->send($ctx)
    ->shouldBeQueued()
    ->run();
```

## Validation Rules

All per-step configuration methods throw `InvalidPipelineDefinition` on invalid input:

| Method | Constraint | Extra rule |
|--------|-----------|------------|
| `onQueue(string)` | Non-empty string | Must be called after at least one step |
| `onConnection(string)` | Non-empty string | Must be called after at least one step |
| `sync()` | No argument | Must be called after at least one step |
| `retry(int)` | `>= 0` | Must be called after at least one step |
| `backoff(int)` | `>= 0` | Must be called after at least one step |
| `timeout(int)` | `>= 1` | Must be called after at least one step |
| `defaultQueue(string)` | Non-empty string | Can be called before any step |
| `defaultConnection(string)` | Non-empty string | Can be called before any step |
| `defaultRetry(int)` | `>= 0` | Can be called before any step |
| `defaultBackoff(int)` | `>= 0` | Can be called before any step |
| `defaultTimeout(int)` | `>= 1` | Can be called before any step |

The "must be called after at least one step" constraint exists because per-step methods modify the last added step. Calling them on an empty builder raises a clear error pointing to the corresponding `default*()` method.

## Complete Example

A realistic order-fulfillment pipeline mixing every per-step configuration knob:

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make()
    // Pipeline-level defaults: all steps go to the orders queue with 2 retries.
    ->defaultQueue('orders')
    ->defaultConnection('redis')
    ->defaultRetry(2)
    ->defaultBackoff(5)
    ->defaultTimeout(30)

    // Step 1: synchronous validation (blocks the caller).
    ->step(ValidateOrder::class)->sync()

    // Step 2: payment needs a dedicated queue and tighter timeout.
    ->step(ChargeCustomer::class)
        ->onQueue('payments')
        ->onConnection('redis-payments')
        ->retry(3)
        ->backoff(10)
        ->timeout(60)

    // Step 3: inventory uses pipeline defaults.
    ->step(ReserveInventory::class)

    // Step 4: external API needs more retries and longer timeout.
    ->step(CallShippingApi::class)
        ->retry(5)
        ->backoff(15)
        ->timeout(120)

    // Step 5: notification uses pipeline defaults but a separate queue.
    ->step(SendConfirmation::class)->onQueue('notifications')

    ->send(new OrderContext(order: $order))
    ->shouldBeQueued()
    ->run();
```

For the alternative execution verb `Pipeline::dispatch([...])`, see [Dispatch Verb](dispatch-verb.md). For queue semantics and serialization constraints, see [Queued Pipelines](queued-pipelines.md).
