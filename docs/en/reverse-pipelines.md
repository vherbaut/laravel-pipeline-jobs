# Reverse Pipelines

`PipelineBuilder::reverse()` produces a new pipeline with **the outer step order reversed**. It is useful for undo style workflows, mirror tests (forward run followed by reverse run), and rollback orchestration that cannot use the saga `compensateWith()` contract (see [Saga Compensation](saga-compensation.md)).

## Quick example

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

$forward = JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    ReserveInventory::class,
    SendConfirmation::class,
]);

$forward->send(new OrderContext(order: $order))->run();

// Later: run the exact same steps in reverse.
$forward->reverse()->send(new OrderContext(order: $order))->run();
// Effective order: SendConfirmation, ReserveInventory, ChargeCustomer, ValidateOrder.
```

`reverse()` returns a **new** `PipelineBuilder` instance. The original builder is untouched so you can keep both variants side by side.

## What is reversed

Only the **outer positions** of the pipeline definition flip. Inner structures (parallel groups, nested pipelines, conditional branches) are preserved **as is**.

```php
$builder = JobPipeline::make()
    ->step(StepA::class)
    ->parallel([SubA::class, SubB::class])
    ->nest(JobPipeline::make([InnerA::class, InnerB::class]))
    ->step(StepZ::class);

$builder->reverse();
// Effective outer order: StepZ, NestedPipeline([InnerA, InnerB]), ParallelGroup([SubA, SubB]), StepA.
// Sub steps inside the parallel group stay [SubA, SubB].
// Inner steps inside the nested pipeline stay [InnerA, InnerB].
```

If you need inner reversal too, call `->reverse()` on the inner builder before nesting.

## What is preserved

Every pipeline level field is copied verbatim onto the reversed builder.

- `send()` context (instance or closure).
- `shouldBeQueued()` flag.
- `dispatchEvents()` flag.
- `return()` callback.
- `FailStrategy` (`StopImmediately`, `StopAndCompensate`, `SkipAndContinue`).
- Default queue, connection, retry, backoff, timeout.
- All lifecycle hooks (`beforeEach`, `afterEach`, `onStepFailed`).
- Pipeline level callbacks (`onSuccess`, `onFailure`, `onComplete`).
- Admission gates (`rateLimit`, `maxConcurrent`).

Per step configuration on each `StepDefinition` (queue, retry, `when`, `compensateWith`, etc.) also travels with the step. A step with a `when()` predicate still evaluates that predicate at runtime against the live context at its **new** position in the reversed pipeline.

## Compensation interaction

Compensation follows the execution order, not the declaration order. In a reversed pipeline, the compensation chain walks backwards over the steps that **actually executed**. So a reversed pipeline that fails at step 3 compensates steps 1 and 2 of the reversed execution (which are the last two steps of the original declaration).

This is consistent with how the saga pattern is implemented and does not require any special handling.

## Testing

`FakePipelineBuilder::reverse()` mirrors the real builder so `Pipeline::fake()` works transparently with reversed pipelines.

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::fake();

Pipeline::make([StepA::class, StepB::class, StepC::class])
    ->reverse()
    ->run();

Pipeline::assertPipelineRanWith([StepC::class, StepB::class, StepA::class]);
```

Under `Pipeline::fake()->recording()`, actual execution follows the reversed order and `assertStepsExecutedInOrder([...])` accepts the reversed class list.

## When to prefer `compensateWith()` instead

`reverse()` runs the **same step classes** in the opposite order. It does not invoke a dedicated rollback class per step. When each forward step has a distinct undo operation (refund, release, notify), use `compensateWith()` on each step so failures trigger the compensation chain automatically. See [Saga Compensation](saga-compensation.md) for that contract.

Use `reverse()` when the forward and the undo logic live in the same class (idempotent steps with a direction flag, test setup/teardown symmetry, migration roll forward then roll back), or when you need to inspect the reversed definition separately from a failure driven rollback.
