# Lifecycle Hooks and Observability

Laravel Pipeline Jobs exposes six lifecycle hooks that let you observe pipelines without touching the job classes themselves. They fall into two categories.

| Category | Methods | Fires per |
|----------|---------|-----------|
| Per-step hooks | `beforeEach()`, `afterEach()`, `onStepFailed()` | Every non-skipped step |
| Pipeline-level callbacks | `onSuccess()`, `onFailure(Closure)`, `onComplete()` | Once per pipeline outcome |

All six hooks work identically in synchronous, queued, and recording modes. Hook closures that cross the queue boundary are wrapped in `SerializableClosure`, so any captured variables must be serializable.

## Table of Contents

- [Per-Step Hooks](#per-step-hooks)
  - [beforeEach](#beforeeach)
  - [afterEach](#aftereach)
  - [onStepFailed](#onstepfailed)
  - [Append Semantics](#append-semantics)
  - [Error Handling in Per-Step Hooks](#error-handling-in-per-step-hooks)
- [Pipeline-Level Callbacks](#pipeline-level-callbacks)
  - [onSuccess](#onsuccess)
  - [onFailure (Closure)](#onfailure-closure)
  - [onComplete](#oncomplete)
  - [Last-Write-Wins Semantics](#last-write-wins-semantics)
  - [Error Handling in Pipeline Callbacks](#error-handling-in-pipeline-callbacks)
- [Firing Order Reference](#firing-order-reference)
- [Interaction with FailStrategy::SkipAndContinue](#interaction-with-failstrategyskipandcontinue)
- [Queued Mode Notes](#queued-mode-notes)
- [Testing Hooks](#testing-hooks)
- [Complete Example](#complete-example)

## Per-Step Hooks

Per-step hooks receive a minimal `StepDefinition` snapshot and the live `PipelineContext` (which may be `null` when no context was sent via `->send()`).

### beforeEach

Fires immediately before each non-skipped step's `handle()` method runs.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

JobPipeline::make([ValidateOrder::class, ChargeCustomer::class])
    ->beforeEach(function (StepDefinition $step, ?PipelineContext $context): void {
        Log::info("Starting {$step->jobClass}");
    })
    ->send(new OrderContext(order: $order))
    ->run();
```

Hooks do not fire for steps skipped via `when()` / `unless()` conditions. The skip check precedes the hook firing.

### afterEach

Fires after the step's `handle()` method returns successfully, before the manifest marks the step completed.

```php
JobPipeline::make([ValidateOrder::class, ChargeCustomer::class])
    ->afterEach(function (StepDefinition $step, ?PipelineContext $context): void {
        Log::info("Done {$step->jobClass}");
    })
    ->run();
```

Mutations performed by `handle()` are visible on the context argument. The hook does not fire for steps that threw. The `onStepFailed` branch applies instead.

### onStepFailed

Fires when a step throws, including throws from `beforeEach` or `afterEach`.

```php
JobPipeline::make([ChargeCustomer::class])
    ->onStepFailed(function (StepDefinition $step, ?PipelineContext $context, \Throwable $exception): void {
        Log::error("Step {$step->jobClass} failed", ['exception' => $exception]);
    })
    ->run();
```

`onStepFailed` fires BEFORE the `FailStrategy` branching applies, so the hook runs regardless of whether the strategy is `StopImmediately`, `StopAndCompensate`, or `SkipAndContinue`.

### Append Semantics

Per-step hooks are append-semantic. Registering the same hook type multiple times runs all closures in registration order.

```php
JobPipeline::make([ProcessOrder::class])
    ->beforeEach(fn ($step) => Log::info("[metrics] {$step->jobClass}"))
    ->beforeEach(fn ($step) => Tracer::start($step->jobClass))
    ->run();
```

Both closures fire per step, in the order they were registered. This is ergonomically intentional. Multiple observers (logs, metrics, tracing) are a common need.

### Error Handling in Per-Step Hooks

| Hook throws | Effect |
|-------------|--------|
| `beforeEach` or `afterEach` | Treated as a step failure. The step is not marked completed. `onStepFailed` hooks fire with the hook's exception. `FailStrategy` branching then applies. |
| `onStepFailed` | Propagates and replaces the original step exception. `FailStrategy` branching is bypassed for the current failure. Subsequent `onStepFailed` hooks in the array do not fire. |

No hook exception is silently swallowed.

## Pipeline-Level Callbacks

Pipeline-level callbacks fire once per pipeline outcome, not per step.

### onSuccess

Fires when the pipeline reaches its terminal success branch.

```php
JobPipeline::make([ProcessOrder::class, SendReceipt::class])
    ->onSuccess(function (?PipelineContext $context): void {
        Notification::send($user, new OrderProcessed($context->order));
    })
    ->run();
```

"Success" means "the pipeline completed its intended flow without a terminal failure", not "every step succeeded". Under `FailStrategy::SkipAndContinue` the pipeline reaches the success tail even when intermediate steps failed, so `onSuccess` still fires.

### onFailure (Closure)

The `onFailure()` method accepts either a `FailStrategy` enum (the pre-existing saga-strategy setter) or a `Closure` (a pipeline-level failure callback). They are orthogonal storage slots. You may call both.

```php
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;

JobPipeline::make([ReserveInventory::class, ChargeCustomer::class])
    ->onFailure(FailStrategy::StopAndCompensate)
    ->onFailure(function (?PipelineContext $context, \Throwable $exception): void {
        Alert::send("Pipeline failed: {$exception->getMessage()}");
    })
    ->run();
```

The closure form fires once on terminal pipeline failure under `StopImmediately` or `StopAndCompensate`. It fires AFTER per-step `onStepFailed` hooks, AFTER compensation (sync: chain has fully run, queued: chain has been dispatched), and BEFORE the terminal rethrow.

Under `FailStrategy::SkipAndContinue` the closure does NOT fire, because there is no terminal throw. Use per-step `onStepFailed` for per-step failure observability in tolerant pipelines.

### onComplete

Fires AFTER `onSuccess` on the success path and AFTER `onFailure` on the failure path.

```php
JobPipeline::make([ProcessOrder::class])
    ->onComplete(function (?PipelineContext $context): void {
        Metrics::record('pipeline.completed');
    })
    ->run();
```

`onComplete` runs on both terminal branches (success or failure), unless a preceding callback threw.

### Last-Write-Wins Semantics

Pipeline-level callbacks are last-write-wins. Registering the same callback type twice discards the first one.

```php
JobPipeline::make([ProcessOrder::class])
    ->onSuccess($firstCallback)  // discarded
    ->onSuccess($secondCallback) // wins
    ->run();
```

This contrasts with per-step hooks, which are append-semantic. The rationale: a pipeline has ONE terminal outcome, so one notification / one metric / one alert is the ergonomic shape. Multiple observers at the pipeline level would create silent duplicates.

### Error Handling in Pipeline Callbacks

| Callback throws | Sync behaviour | Queued behaviour |
|-----------------|----------------|------------------|
| `onSuccess` | Exception propagates unwrapped. `onComplete` is skipped. | Wrapper job marked failed. `onComplete` is skipped. |
| `onFailure(Closure)` | Wrapped as `StepExecutionFailed` via `StepExecutionFailed::forCallbackFailure()`. The original step exception is preserved on `$originalStepException`, and the callback exception is attached as `$previous`. `onComplete` is skipped. | Wrapper job marked failed with the same wrapper. `onComplete` is skipped. |
| `onComplete` on success path | Exception bubbles out unwrapped. | Wrapper job marked failed. |
| `onComplete` on failure path | Wrapped as `StepExecutionFailed::forCallbackFailure()`. Replaces the originally-intended rethrow. Original step exception preserved on `$originalStepException`. | Wrapper job marked failed. |

The `forCallbackFailure` wrapping preserves observability: readers can inspect both the callback fault (via `getPrevious()`) and the original step fault (via `$originalStepException`).

## Firing Order Reference

Success path (sync or queued, any strategy):

1. Step N: `beforeEach` hooks fire (registration order)
2. Step N: `handle()` executes
3. Step N: `afterEach` hooks fire (registration order)
4. (Repeat for remaining steps)
5. `onSuccess` callback fires
6. `onComplete` callback fires
7. Executor returns

Failure path under `StopImmediately`:

1. Step N throws
2. `onStepFailed` hooks fire (registration order)
3. `onFailure(Closure)` callback fires
4. `onComplete` callback fires
5. `StepExecutionFailed` rethrown (sync) or raw exception rethrown (queued)

Failure path under `StopAndCompensate`:

1. Step N throws
2. `onStepFailed` hooks fire
3. Compensation chain runs (sync) or is dispatched (queued)
4. `onFailure(Closure)` callback fires (sync: post-compensation, queued: post-dispatch but pre-execution)
5. `onComplete` callback fires
6. `StepExecutionFailed` rethrown

Failure path under `SkipAndContinue`:

1. Step N throws
2. `onStepFailed` hooks fire
3. Log warning, advance to next step
4. (No terminal throw, pipeline eventually reaches the success tail)
5. `onSuccess` callback fires
6. `onComplete` callback fires

## Interaction with FailStrategy::SkipAndContinue

`SkipAndContinue` converts step failures into continuations. The pipeline always terminates via the success branch.

- `onStepFailed` hooks fire on every failing step.
- `onSuccess` fires at the end, even when intermediate steps failed.
- `onFailure(Closure)` does NOT fire (no terminal throw).
- `onComplete` fires after `onSuccess`.

If you need pipeline-level observability of "did any step fail under SkipAndContinue?", track that state yourself in the context via an `onStepFailed` hook.

## Queued Mode Notes

All hook and callback closures are wrapped in `SerializableClosure` when the pipeline runs queued. Non-serializable closures produce the standard `SerializableClosure` exception at dispatch time (`PipelineBuilder::run()` or `::toListener()`).

Pipeline-level callbacks fire on the worker that processes the terminal step. The `onFailure(Closure)` callback under `StopAndCompensate` in queued mode runs BEFORE compensation jobs execute. They run on their own workers subsequently. Plan your callback side effects accordingly.

Variables captured through `use` must also be serializable. Avoid capturing resources, anonymous classes, or live database handles inside hook closures. Load what you need from the `PipelineContext` argument instead.

## Testing Hooks

Hooks fire identically under `Pipeline::fake()->recording()`. The `FakePipelineBuilder` exposes the same six methods as pass-through delegates.

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

it('fires the success callback', function () {
    $fake = Pipeline::fake()->recording();
    $successFired = false;

    JobPipeline::make([ProcessOrder::class])
        ->onSuccess(function () use (&$successFired) {
            $successFired = true;
        })
        ->send(new OrderContext(order: $order))
        ->run();

    expect($successFired)->toBeTrue();
});
```

In non-recording `Pipeline::fake()` mode, hooks are NOT invoked because no steps run. The fake only captures the definition.

No dedicated hook assertions ship with the package today (`assertHookRegistered()`, `assertHookFired()`). Use local boolean flags or spy closures, as shown above.

## Complete Example

A full pipeline wiring every hook type, illustrating the firing order.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

JobPipeline::make()
    ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
    ->step(ChargeCustomer::class)->compensateWith(RefundCustomer::class)
    ->step(SendConfirmation::class)

    // Per-step hooks (append-semantic).
    ->beforeEach(fn (StepDefinition $s, ?PipelineContext $c) => Log::info("→ {$s->jobClass}"))
    ->afterEach(fn (StepDefinition $s, ?PipelineContext $c) => Log::info("✓ {$s->jobClass}"))
    ->onStepFailed(fn (StepDefinition $s, ?PipelineContext $c, \Throwable $e) => Sentry::captureException($e))

    // Pipeline-level callbacks (last-write-wins).
    ->onSuccess(fn (?PipelineContext $c) => Notification::send($user, new OrderCompleted($c->order)))
    ->onFailure(FailStrategy::StopAndCompensate)
    ->onFailure(fn (?PipelineContext $c, \Throwable $e) => Alert::send("Order pipeline failed"))
    ->onComplete(fn (?PipelineContext $c) => Metrics::record('orders.pipeline.completed'))

    ->send(new OrderContext(order: $order))
    ->run();
```
