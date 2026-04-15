# Saga Pattern (Compensation)

In distributed systems, when a multi step process fails partway through, you often need to undo the steps that already completed. This is the **saga pattern**, and it is built directly into the pipeline builder.

```php
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;

JobPipeline::make()
    ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
    ->step(ChargeCustomer::class)->compensateWith(RefundCustomer::class)
    ->step(CreateShipment::class)->compensateWith(CancelShipment::class)
    ->onFailure(FailStrategy::StopAndCompensate)
    ->send(new OrderContext(order: $order))
    ->run();
```

## Table of Contents

- [Failure Strategies](#failure-strategies)
- [How StopAndCompensate Works](#how-stopandcompensate-works)
- [How SkipAndContinue Works](#how-skipandcontinue-works)
- [Writing a Compensation Job](#writing-a-compensation-job)
- [Inspecting the Failure](#inspecting-the-failure)
- [Observability on Compensation Failure](#observability-on-compensation-failure)

## Failure Strategies

Every pipeline declares how failures are handled via `onFailure(FailStrategy)`. The default is `StopImmediately`, which means a pipeline with only `compensateWith()` mappings and no `onFailure()` call does **not** trigger compensation. You must explicitly opt in.

| Strategy | Behaviour on step failure |
|----------|---------------------------|
| `FailStrategy::StopImmediately` (default) | Rethrows the failure as `StepExecutionFailed`. No compensation runs. |
| `FailStrategy::StopAndCompensate` | Runs the compensation chain in reverse order over completed steps, then rethrows as `StepExecutionFailed`. |
| `FailStrategy::SkipAndContinue` | Logs a warning, skips the failing step, and resumes with the next one. The pipeline does not throw. No compensation runs. |

## How StopAndCompensate Works

1. Steps execute in order: `ReserveInventory`, then `ChargeCustomer`, then `CreateShipment`.
2. If `CreateShipment` throws an exception, compensation kicks in.
3. Only the **completed** steps are compensated, in **reverse order**: `RefundCustomer` first, then `ReleaseInventory`.
4. `CancelShipment` is **not** called because `CreateShipment` never completed.
5. The original step exception is rethrown as `StepExecutionFailed` once the chain finishes (best effort in sync mode, the chain halts on first throwing compensation in queued mode).

## How SkipAndContinue Works

`SkipAndContinue` is useful for tolerant pipelines where a failing step should not abort the whole run. When a step throws:

1. The failure is recorded on the manifest (`failedStepClass`, `failedStepIndex`, `failureException`).
2. A `Log::warning('Pipeline step skipped under SkipAndContinue', [...])` is emitted with the pipeline id, step class, step index, and exception message.
3. The pipeline advances to the next step and keeps executing.
4. **No compensation runs** for skipped steps, even if `compensateWith()` was declared.
5. If a later step succeeds, the failure fields are cleared. If another step fails, the last failure wins.

```php
JobPipeline::make()
    ->step(FetchRemoteData::class)
    ->step(ParseOptionalSection::class) // may throw, will be skipped
    ->step(PersistResults::class)
    ->onFailure(FailStrategy::SkipAndContinue)
    ->send(new ImportContext)
    ->run();
```

## Writing a Compensation Job

**Trait based (same shape as a regular step).** The legacy approach: inject the manifest via the `InteractsWithPipeline` trait and implement `handle()`.

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class RefundCustomer
{
    use InteractsWithPipeline;

    public function handle(PaymentService $payments): void
    {
        $context = $this->pipelineContext();
        $payments->refund($context->invoice);

        // Optional: inspect why the pipeline failed
        $failure = $this->failureContext();
        if ($failure !== null) {
            logger()->info("Compensating after {$failure->failedStepClass}");
        }
    }
}
```

**Contract based (recommended for new code).** Implement the `CompensableJob` interface. The executor invokes `compensate()` with the pipeline context and, optionally, a `FailureContext` snapshot.

```php
use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;

class RefundCustomer implements CompensableJob
{
    public function __construct(private PaymentService $payments) {}

    public function compensate(PipelineContext $context, ?FailureContext $failure = null): void
    {
        $this->payments->refund($context->invoice);
    }
}
```

Implementations may keep the single argument signature (`compensate(PipelineContext $context)`). The executor inspects the signature via reflection and only passes the `FailureContext` when you widen to two parameters.

## Inspecting the Failure

`FailureContext` is a readonly value object carrying:

| Property | Type | Description |
|----------|------|-------------|
| `failedStepClass` | `string` | Fully qualified class name of the step that threw. |
| `failedStepIndex` | `int` | Zero based index of the failing step. |
| `exception` | `?\Throwable` | The original throwable (non null in sync mode, always null in queued mode per NFR19 because `Throwable` is excluded from the serialized payload). |

You can access it from any compensation job.

- **Contract based jobs** receive it as the optional second argument of `compensate()`.
- **Trait based jobs** call `$this->failureContext()` inside `handle()` to get the same snapshot.

## Observability on Compensation Failure

When a compensation job itself throws:

- **Sync pipelines.** A `Log::error('Pipeline compensation failed', [...])` line is emitted and the `Vherbaut\LaravelPipelineJobs\Events\CompensationFailed` event is dispatched. The per compensation exception is swallowed so the remaining compensations continue to run (best effort semantics).
- **Queued pipelines.** The wrapper job lands in `failed_jobs` with Laravel's standard record. After the wrapper exhausts its tries (`$tries = 1`), the `failed()` hook emits a `Log::error('Pipeline compensation failed after retries', [...])` line and dispatches the same `CompensationFailed` event. The `Bus::chain` halts on the first failing compensation in queued mode (documented divergence with sync best effort).

The `CompensationFailed` event fires **unconditionally**, independently of any user opt in to pipeline events. It is designed for operational alerting.

```php
use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;

Event::listen(CompensationFailedEvent::class, function (CompensationFailedEvent $event): void {
    // $event->pipelineId
    // $event->compensationClass
    // $event->failedStepClass (nullable, string in production)
    // $event->originalException (null in queued mode, Throwable in sync)
    // $event->compensationException (Throwable, always non null)
    Sentry::captureMessage("Rollback failed: {$event->compensationClass}");
});
```

> The exception class `Vherbaut\LaravelPipelineJobs\Exceptions\CompensationFailed` shares its basename with the event. When importing both in the same file, alias one: `use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;`.
