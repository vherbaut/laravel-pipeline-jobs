# Conditional Branching

A pipeline sometimes needs to pick **one path among several** based on context state at execution time. Conditional branches model this pattern : a selector (closure) returns a key, and the pipeline runs the step associated with that key. After the selected branch completes, the pipeline converges on the next outer step.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\Step;

JobPipeline::make([
    ValidateOrder::class,
    Step::branch(
        fn (OrderContext $ctx) => $ctx->order->customerType,
        [
            'b2b' => ProcessB2BOrder::class,
            'b2c' => ProcessB2COrder::class,
            'reseller' => ProcessResellerOrder::class,
        ],
    ),
    SendConfirmation::class,
])
    ->send(new OrderContext(order: $order))
    ->run();
```

Depending on the `customerType` value, only one of the three branches runs. `SendConfirmation` executes next regardless of the branch chosen (FR27 convergence semantic).

## Table of Contents

- [Two equivalent factories](#two-equivalent-factories)
- [Accepted branch values](#accepted-branch-values)
- [Selector semantics](#selector-semantics)
- [Queued execution](#queued-execution)
- [Sub pipelines as branch values](#sub-pipelines-as-branch-values)
- [Supported nestings](#supported-nestings)
- [FailStrategy and selector failures](#failstrategy-and-selector-failures)
- [Compensation and shared map](#compensation-and-shared-map)
- [Hooks and callbacks](#hooks-and-callbacks)
- [Payload constraints](#payload-constraints)
- [Testing](#testing)

## Two equivalent factories

`Step::branch(...)` is the preferred factory inside a `make([...])` array :

```php
JobPipeline::make([
    A::class,
    Step::branch(fn ($ctx) => $ctx->type, ['x' => StepX::class, 'y' => StepY::class]),
    C::class,
]);
```

`JobPipeline::branch(...)` is its strict alias, useful when the file already imports `JobPipeline` but not `Step`.

The fluent API exposes `->branch(...)` on the builder :

```php
JobPipeline::make()
    ->step(A::class)
    ->branch(fn ($ctx) => $ctx->type, ['x' => StepX::class, 'y' => StepY::class])
    ->step(C::class)
    ->run();
```

All three forms produce exactly the same `ConditionalBranch`. The optional third argument `name` serves observability and test assertions.

## Accepted branch values

A branch value can be :

| Form | Behavior |
|------|----------|
| `class-string` | Automatically wrapped via `StepDefinition::fromJobClass($class)`. |
| Pre built `StepDefinition` | Kept as is (preserves compensation, retry, queue, etc.). |
| `NestedPipeline` | A full sub pipeline runs if this branch is selected. |
| `PipelineBuilder` | Auto wrapped into a `NestedPipeline`. |
| `PipelineDefinition` | Auto wrapped into a `NestedPipeline`. |

`ParallelStepGroup` values are **rejected** at build time (`InvalidPipelineDefinition::parallelInsideConditionalBranch()`). To combine parallel and branch, wrap the parallel group inside a `NestedPipeline` and pass that sub pipeline as a branch value.

Keys must be non empty, non whitespace only strings. An auto indexed array (numeric keys) is rejected via `InvalidPipelineDefinition::blankBranchKey()`.

## Selector semantics

The selector is a `Closure(PipelineContext): string`. It is evaluated **exactly once** at the moment the branch is about to run :

- In sync mode : inline inside `SyncExecutor::executeConditionalBranch`.
- In queued mode : on the branch wrapper, before the next wrapper is dispatched. The manifest is then rewritten (**rebrand then dispatch** pattern) to replace the branch with the selected value. Downstream wrappers see a plain flat step or a regular sub pipeline, never a branch.

This "exactly once" guarantee matters for selectors with side effects (logging, cache lookups, metric counters) : they are not replayed on a sub job retry.

The selector receives the live `PipelineContext` (including mutations from earlier steps). It can read but should avoid mutating the context (selector mutations persist and can make behavior non deterministic).

## Queued execution

In queued mode, the selector is serialized with the manifest, so it must be **serializable**. The builder wraps it automatically in `SerializableClosure`. This implies :

1. Variables captured via `use(...)` must be serializable (no resources, no active DB connections, no anonymous classes).
2. A selector capturing `$this` from a non serializable class fails at enqueue time.

Prefer pure selectors that only use the `PipelineContext` :

```php
// Good : selector only reads the context.
Step::branch(fn (OrderContext $ctx) => $ctx->order->priority, [...]);

// Avoid : captures non serializable $this.
// Step::branch(fn ($ctx) => $this->resolver->decide($ctx), [...]);
```

When the decision depends on external state, load it in an earlier step, store it on the context, and read it from the selector.

## Sub pipelines as branch values

A branch value can be a full sub pipeline. The nested cursor mechanism (see [pipeline-nesting.md](pipeline-nesting.md)) takes over :

```php
Step::branch(
    fn (OrderContext $ctx) => $ctx->order->shippingMode,
    [
        'express' => JobPipeline::make([
            ReservePriorityCarrier::class,
            NotifyExpressWarehouse::class,
            ScheduleSameDayPickup::class,
        ]),
        'standard' => JobPipeline::make([
            ReserveStandardCarrier::class,
            NotifyWarehouse::class,
        ]),
    ],
);
```

If `shippingMode === 'express'`, the three steps of the express sub pipeline run sequentially and share the outer context. The outer step following the branch runs next.

## Supported nestings

| Composition | Support |
|-------------|---------|
| Branch at the root | ✅ |
| Branch inside a sub pipeline (branch inside nested) | ✅ |
| Sub pipeline as branch value (nested as branch value) | ✅ |
| Branch nested inside another branch (via sub pipeline) | ✅ recursive |
| Parallel as branch value | ❌ wrap it in a `NestedPipeline` |
| Branch inside a parallel group | ❌ rejected at build time |

The parallel / branch restriction comes from the "one branch wins" semantic, which conflicts with the per sub step deep clone of `Bus::batch()`.

## FailStrategy and selector failures

All three outer pipeline strategies apply to branch failures, including selector failures (throw, non string return, unknown key) :

| Strategy | Selector failure behavior | Selected branch failure behavior |
|----------|---------------------------|----------------------------------|
| `StopImmediately` (default) | Rethrows `StepExecutionFailed` wrapping the cause. `onFailure`/`onComplete` callbacks fire. | Same, with the failing step as context. |
| `StopAndCompensate` | Runs the compensation chain on **previously completed** steps, then rethrows. | Same, plus the failing sub step if it had completed. |
| `SkipAndContinue` | Logs a warning, advances past the branch, continues with the next outer step. | Same. |

```php
JobPipeline::make([
    Step::make(ReserveStock::class)->withCompensation(ReleaseStock::class),
    Step::branch(
        fn (OrderContext $ctx) => $ctx->order->customerType, // may throw
        ['b2b' => B2BPath::class, 'b2c' => B2CPath::class],
    ),
    SendConfirmation::class,
])
    ->onFailure(FailStrategy::StopAndCompensate)
    ->send($context)
    ->run();

// If the selector throws : ReleaseStock fires (saga preserved),
// then StepExecutionFailed is rethrown.
```

No `onStepFailed` hook fires for selector failures (the selector is infrastructure, not a user step). Pipeline level callbacks (`onFailure`, `onComplete`) fire normally.

## Compensation and shared map

The compensation map (`compensationMapping`) is built at build time by merging **all branches**. At runtime only one branch runs, but the map includes compensations from every alternative.

Edge case to know : if two branches declare the **same job class** with **different** compensations, `array_merge` applies "last declared wins" semantics. If the first branch runs and fails under `StopAndCompensate`, the compensation invoked is that of the **last** declared branch, not of the executed one.

```php
// Avoid : FooJob with two different compensations depending on the branch.
Step::branch(fn ($ctx) => $ctx->path, [
    'a' => Step::make(FooJob::class)->withCompensation(CompensateA::class),
    'b' => Step::make(FooJob::class)->withCompensation(CompensateB::class),
]);
// If branch 'a' runs and fails, CompensateB is invoked (map["FooJob"] = CompensateB::class).
```

The workaround is to use **distinct** job classes per branch (each with its appropriate compensation), or to accept the documented semantic.

## Hooks and callbacks

The outer pipeline hooks (`beforeEachHooks`, `afterEachHooks`, `onStepFailedHooks`) fire for **the actually executed step** of the selected branch (or for every sub step if the value is a sub pipeline). Hooks declared inside a sub pipeline serving as a branch value are ignored (consistent with the inheritance rule from [pipeline-nesting.md](pipeline-nesting.md)).

Terminal callbacks (`onSuccessCallback`, `onFailureCallback`, `onCompleteCallback`) fire **once** at the end of the outer pipeline.

## Payload constraints

The manifest carries the **full description** of every branch (selector wrapped in `SerializableClosure` plus each branch value with its configuration and conditions). The cost is proportional to the cumulative size, regardless of which branch eventually runs.

Approximate size per branch :

```
size(selector_closure) + sum_k(size(branch_value_k) + size(branch_config_k) + size(branch_condition_k))
```

For queued pipelines approaching the SQS payload limit (256 KB, NFR11), reduce the number of branches or extract the more complex ones into sub pipelines loaded dynamically upstream.

## Testing

`PipelineFake` records branches in the recorded definition. The dedicated assertion is `assertConditionalBranchExecuted(array $expectedKeys, ?string $name = null, ?int $pipelineIndex = null)` :

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::fake();

Pipeline::make([
    ValidateOrder::class,
    Step::branch(
        fn (OrderContext $ctx) => $ctx->order->customerType,
        ['b2b' => B2BPath::class, 'b2c' => B2CPath::class],
        name: 'customer-routing',
    ),
])->send($context)->run();

Pipeline::assertConditionalBranchExecuted(['b2b', 'b2c'], name: 'customer-routing');
```

The assertion verifies that the declared keys (in insertion order) match `$expectedKeys`. For real execution (which branch ran, with what context), use `Pipeline::fake()->recording()`, which replicates `SyncExecutor` semantics (selector evaluated, branch executed, snapshots captured) without touching `Bus::batch()` on the queue.
