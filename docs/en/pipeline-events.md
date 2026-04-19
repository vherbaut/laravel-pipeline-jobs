# Pipeline Events

Pipelines can broadcast Laravel events at three lifecycle points so external listeners (metrics, audit logging, alerting, tenant observability) can react to what happens without touching the pipeline itself. Event dispatch is **opt in**. When the flag is off, no event is ever allocated.

## Three events at three lifecycle points

| Event | When it fires |
|-------|---------------|
| `PipelineStepCompleted` | After each step's `handle()` (or `__invoke()`) returns successfully. |
| `PipelineStepFailed` | Immediately after a step throws, before `onStepFailed` per step hooks fire. |
| `PipelineCompleted` | Once at the terminal exit of the run (success tail or failure tail). |

A fourth event, `CompensationFailed`, is always dispatched when the compensation chain itself throws. It is operational alerting, not a lifecycle signal, so it is not gated by the opt in flag.

## Opt in with `dispatchEvents()`

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    SendConfirmation::class,
])
    ->dispatchEvents()
    ->send(new OrderContext(order: $order))
    ->run();
```

Calling `dispatchEvents()` flips a boolean on the builder. It is idempotent (calling it twice has no extra effect). Without the flag, the centralized event dispatcher short circuits before even constructing the event payload. Zero overhead when unused.

## Listening to events

Register listeners in `EventServiceProvider` (or via auto discovery) exactly like any Laravel event.

```php
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;

Event::listen(PipelineStepCompleted::class, function (PipelineStepCompleted $event): void {
    Log::info('step completed', [
        'pipeline_id' => $event->pipelineId,
        'step_index'  => $event->stepIndex,
        'step_class'  => $event->stepClass,
    ]);
});

Event::listen(PipelineStepFailed::class, function (PipelineStepFailed $event): void {
    report($event->exception);
});

Event::listen(PipelineCompleted::class, function (PipelineCompleted $event): void {
    Metric::increment('pipeline.completed', ['pipeline_id' => $event->pipelineId]);
});
```

## Event payloads

All events carry a `pipelineId` string that correlates the three events of a single run. Use it as a correlation key in logs and metrics.

```php
final class PipelineStepCompleted
{
    public function __construct(
        public readonly string $pipelineId,
        public readonly ?PipelineContext $context,
        public readonly int $stepIndex,
        public readonly string $stepClass,
    ) {}
}
```

`PipelineStepFailed` adds a `Throwable $exception`. `PipelineCompleted` drops `stepIndex` and `stepClass` (it fires once, not per step).

## Index semantics

The `stepIndex` on `PipelineStepCompleted` and `PipelineStepFailed` is the **outer position** in the user authored pipeline.

- Flat top level step: the step's own index.
- Parallel sub step: the outer group's index (the sub step is disambiguated by `stepClass`).
- Nested inner step: the top level outer index that holds the nested pipeline.
- Branch inner step: the branch group's outer index (only the selected branch fires events).

A step skipped via `when()` / `unless()` fires no events. Under `SkipAndContinue`, a failed step fires `PipelineStepFailed` but not `PipelineStepCompleted`.

## Sync, queued, and recording parity

The three events fire identically across execution modes.

- **Sync.** `SyncExecutor` dispatches after `afterEach` hooks and after the manifest flips `markStepCompleted()`.
- **Queued.** `PipelineStepJob` dispatches at the same lifecycle point on the worker before hopping to the next step.
- **Recording.** `Pipeline::fake()->recording()` dispatches through the recording observer so tests using `Event::fake()` capture the same payloads.

`Pipeline::fake()` without `->recording()` does **not** execute steps. It therefore never dispatches these events, even if `dispatchEvents()` is set.

## Queued listeners caveat

Listeners registered with `ShouldQueue` receive a payload that may contain a live `Throwable` on `PipelineStepFailed`. Laravel's queued listener serializer can fail or strip the throwable when routing to the queue. For queued listeners, extract the essentials (class name, message, stack trace string) inside an in process listener that forwards a sanitized payload to the queued work, rather than relying on the `Throwable` surviving queue transport.

## Interaction with hooks and callbacks

Events are orthogonal to the hooks and callbacks shipped in earlier stories.

- Per step hooks (`beforeEach`, `afterEach`, `onStepFailed`) fire in process, on the same worker, synchronously around the step.
- Pipeline level callbacks (`onSuccess`, `onFailure`, `onComplete`) fire in process at terminal exit.
- Events dispatch through Laravel's event dispatcher and can therefore be queued, batched, or observed by any subscriber.

A pipeline using both hooks AND events gets both signals. Hooks fire first (in process, per step), then events dispatch (cross process, observable by any listener).

## Testing

Assert pipeline events with `Event::fake()`:

```php
use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;

Event::fake([PipelineStepCompleted::class, PipelineCompleted::class]);

JobPipeline::make([ValidateOrder::class, ChargeCustomer::class])
    ->dispatchEvents()
    ->send(new OrderContext(order: $order))
    ->run();

Event::assertDispatched(
    PipelineStepCompleted::class,
    fn (PipelineStepCompleted $event) => $event->stepClass === ChargeCustomer::class,
);
Event::assertDispatchedTimes(PipelineCompleted::class, 1);
```

Recording mode fires events too, so tests combining `Pipeline::fake()->recording()` with `Event::fake()` exercise the same code path as production.
