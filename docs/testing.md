# Testing

This package provides a comprehensive testing toolkit that follows the same patterns as Laravel's `Bus::fake()` and `Queue::fake()`. You get a test double, assertion methods, and execution recording, all accessible through the `Pipeline` facade.

## Table of Contents

- [Fake Mode](#fake-mode)
- [Recording Mode](#recording-mode)
- [Available Assertions](#available-assertions)
- [Context Snapshots](#context-snapshots)
- [Compensation Assertions](#compensation-assertions)

## Fake Mode

Fake mode intercepts all pipeline executions without actually running any steps. Useful when you want to verify that a pipeline was dispatched with the correct configuration, without caring about step execution.

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

it('dispatches the order pipeline', function () {
    $fake = Pipeline::fake();

    // Run your application code that triggers a pipeline...
    $service = new OrderService();
    $service->processOrder($order);

    // Assert the pipeline was dispatched
    $fake->assertPipelineRan();
    $fake->assertPipelineRanWith([
        ProcessOrder::class,
        SendReceipt::class,
    ]);
});
```

## Recording Mode

Recording mode goes further: it actually executes your pipeline steps (synchronously) while capturing a full execution trace. You verify not only that a pipeline was dispatched, but that each step executed correctly and modified the context as expected.

Enable recording mode by calling `recording()` on the fake.

```php
it('executes all order steps and updates context', function () {
    $fake = Pipeline::fake()->recording();

    JobPipeline::make([
        ValidateOrder::class,
        ChargeCustomer::class,
        CreateShipment::class,
    ])
        ->send(new OrderContext(order: $order))
        ->run();

    $fake->assertStepExecuted(ValidateOrder::class);
    $fake->assertStepExecuted(ChargeCustomer::class);
    $fake->assertStepExecuted(CreateShipment::class);

    $fake->assertStepsExecutedInOrder([
        ValidateOrder::class,
        ChargeCustomer::class,
        CreateShipment::class,
    ]);

    $fake->assertContextHas('status', 'shipped');
});
```

## Available Assertions

**Pipeline level assertions** (work in both fake and recording modes):

| Method | Description |
|--------|-------------|
| `assertPipelineRan(?Closure $callback)` | At least one pipeline was dispatched. Optional callback to filter. |
| `assertPipelineRanWith(array $jobs)` | A pipeline was dispatched with exactly these job classes. |
| `assertNoPipelinesRan()` | No pipelines were dispatched. |
| `assertPipelineRanTimes(int $count)` | Exactly N pipelines were dispatched. |

**Step execution assertions** (recording mode only):

| Method | Description |
|--------|-------------|
| `assertStepExecuted(string $jobClass)` | This step was executed. |
| `assertStepNotExecuted(string $jobClass)` | This step was not executed. |
| `assertStepsExecutedInOrder(array $jobs)` | Steps executed in exactly this order. |

**Context assertions** (recording mode only):

| Method | Description |
|--------|-------------|
| `assertContextHas(string $property, mixed $value)` | Context property has this value after execution. |
| `assertContext(Closure $callback)` | Custom assertion on the final context. |
| `getRecordedContext()` | Retrieve the recorded context object. |
| `getContextAfterStep(string $jobClass)` | Retrieve the context snapshot taken after a specific step. |

All assertion methods accept an optional `?int $pipelineIndex` parameter. When `null` (the default), assertions apply to the most recently recorded pipeline. Pass an index to target a specific pipeline when multiple pipelines were dispatched in a single test.

## Context Snapshots

One of the most powerful testing features is the ability to inspect the context at any point during execution. After each step completes, the recording executor takes a deep clone of the context. Retrieve these snapshots to verify intermediate state.

```php
it('charges the customer before creating shipment', function () {
    $fake = Pipeline::fake()->recording();

    JobPipeline::make([
        ValidateOrder::class,
        ChargeCustomer::class,
        CreateShipment::class,
    ])
        ->send(new OrderContext(order: $order))
        ->run();

    // After ChargeCustomer, invoice should be set but shipment should not
    $afterCharge = $fake->getContextAfterStep(ChargeCustomer::class);
    expect($afterCharge->invoice)->not->toBeNull();
    expect($afterCharge->shipment)->toBeNull();

    // After CreateShipment, both should be set
    $afterShipment = $fake->getContextAfterStep(CreateShipment::class);
    expect($afterShipment->shipment)->not->toBeNull();
});
```

Invaluable for verifying that each step does its part correctly, independently of other steps.

## Compensation Assertions

When testing saga patterns, verify that the right compensation jobs run (or don't run) when failures occur.

```php
it('compensates completed steps on failure', function () {
    $fake = Pipeline::fake()->recording();

    try {
        JobPipeline::make()
            ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
            ->step(ChargeCustomer::class)->compensateWith(RefundCustomer::class)
            ->step(FailingStep::class)->compensateWith(UndoFailingStep::class)
            ->send(new OrderContext(order: $order))
            ->run();
    } catch (StepExecutionFailed) {
        // Expected
    }

    $fake->assertCompensationWasTriggered();

    // Completed steps were compensated in reverse order
    $fake->assertCompensationRan(RefundCustomer::class);
    $fake->assertCompensationRan(ReleaseInventory::class);

    // The failing step's compensation was NOT run (it never completed)
    $fake->assertCompensationNotRan(UndoFailingStep::class);

    $fake->assertCompensationExecutedInOrder([
        RefundCustomer::class,
        ReleaseInventory::class,
    ]);
});
```

**Compensation assertions** (recording mode only):

| Method | Description |
|--------|-------------|
| `assertCompensationWasTriggered()` | Compensation was triggered during execution. |
| `assertCompensationNotTriggered()` | No compensation was triggered. |
| `assertCompensationRan(string $jobClass)` | This specific compensation job was executed. |
| `assertCompensationNotRan(string $jobClass)` | This specific compensation job was not executed. |
| `assertCompensationExecutedInOrder(array $jobs)` | Compensation jobs ran in exactly this order. |

## Testing Lifecycle Hooks

Lifecycle hooks fire identically under `Pipeline::fake()->recording()`. No dedicated hook assertions ship today. Use local boolean flags or spy closures. See [Lifecycle Hooks](lifecycle-hooks.md#testing-hooks) for examples.
