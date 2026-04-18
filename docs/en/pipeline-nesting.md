# Pipeline Nesting

Some workflows share step sub sequences (validation, enrichment, notifications). Rather than duplicating these sequences across pipelines, you can factor them into a **sub pipeline** and include it as a single position of the outer pipeline.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

$enrichmentPipeline = JobPipeline::make([
    LoadCustomerProfile::class,
    EnrichWithLoyaltyPoints::class,
    EnrichWithRecentOrders::class,
]);

JobPipeline::make([
    ValidateRequest::class,
    JobPipeline::nest($enrichmentPipeline, name: 'enrichment'),
    FormatResponse::class,
])
    ->send(new RequestContext(userId: $id))
    ->run();
```

The `enrichment` sub pipeline runs sequentially inside the outer pipeline, shares the same `PipelineContext`, and takes **one outer position** (as counted by `stepCount()`).

## Table of Contents

- [Building a sub pipeline](#building-a-sub-pipeline)
- [Automatic wrapping](#automatic-wrapping)
- [Context sharing](#context-sharing)
- [Queued execution and cursor](#queued-execution-and-cursor)
- [Configuration and defaults](#configuration-and-defaults)
- [FailStrategy, hooks, and callbacks](#failstrategy-hooks-and-callbacks)
- [Compensation](#compensation)
- [Nesting constraints](#nesting-constraints)
- [Testing](#testing)

## Building a sub pipeline

`JobPipeline::nest(...)` produces a `NestedPipeline` value object. It accepts three input forms :

```php
// From an already built builder
$sub = JobPipeline::make([StepA::class, StepB::class]);
JobPipeline::nest($sub);

// From a PipelineDefinition (immutable snapshot)
$definition = JobPipeline::make([StepA::class, StepB::class])->build();
JobPipeline::nest($definition);

// With an optional name for observability
JobPipeline::nest($sub, name: 'enrichment');
```

The optional name shows up in logs and test assertions.

## Automatic wrapping

To simplify authoring, the builder **automatically wraps** `PipelineBuilder` and `PipelineDefinition` instances it finds in arrays or fluent calls. The two forms below are equivalent :

```php
// Explicit
JobPipeline::make([
    StepA::class,
    JobPipeline::nest(JobPipeline::make([SubA::class, SubB::class])),
    StepC::class,
]);

// Auto wrapped
JobPipeline::make([
    StepA::class,
    JobPipeline::make([SubA::class, SubB::class]),
    StepC::class,
]);
```

Auto wrapping only applies to `PipelineBuilder` and `PipelineDefinition`. An already built `NestedPipeline` is used as is.

## Context sharing

All steps in the sub pipeline read and mutate **the same `PipelineContext`** as the outer pipeline. There is no clone or isolation : an enrichment performed by a sub step is immediately visible to the outer steps that follow.

```php
class EnrichWithLoyaltyPoints
{
    use InteractsWithPipeline;

    public function handle(LoyaltyService $service): void
    {
        $ctx = $this->pipelineContext();
        $ctx->loyaltyPoints = $service->pointsFor($ctx->userId);
    }
}

// After the sub pipeline, $ctx->loyaltyPoints is available in FormatResponse.
```

This sharing contrasts with [parallel](parallel-steps.md) groups, which isolate each sub step in a deep clone.

## Queued execution and cursor

In sync mode, sub steps run inline inside the same process (a plain `foreach` loop).

In queued mode, each sub step becomes an independent wrapper job. A **nested cursor** (`nestedCursor`) is carried on the manifest to track the current position in the tree :

- Cursor `[]` : outer position (root).
- Cursor `[3]` : position 3 of the outer pipeline, which is a sub pipeline.
- Cursor `[3, 1]` : sub step 1 of the sub pipeline at position 3.
- Cursor `[3, 1, 0]` : arbitrary depth (multi level nesting).

The cursor is automatically advanced by `advanceCursorOrOuter()` after each completed sub step. When the cursor walks past the last sub step of a level, it bubbles up one step and moves to the next outer position. No manual plumbing is needed on the user side : dispatch uses the same `PipelineStepJob` for every level.

## Configuration and defaults

Outer pipeline level defaults (`defaultQueue()`, `defaultConnection()`, `defaultRetry()`, `defaultBackoff()`, `defaultTimeout()`) apply to each outer step **unless** overridden by a `Step::make(...)->onQueue(...)`. Sub steps of a `NestedPipeline` use the defaults of **their own** inner `PipelineDefinition`, **not** those of the outer pipeline (rule from Story 8.2).

This lets you compose a reusable pipeline with its own queue / retry policy without having the outer pipeline contaminate it :

```php
$validationPipeline = JobPipeline::make([ValidateA::class, ValidateB::class])
    ->defaultQueue('validation')
    ->defaultRetry(2);

JobPipeline::make([
    JobPipeline::nest($validationPipeline), // ValidateA/B run on the 'validation' queue with retry 2
    ExecuteMain::class, // uses the outer pipeline's defaults
])
    ->defaultQueue('default')
    ->run();
```

## FailStrategy, hooks, and callbacks

The **outer** pipeline's `FailStrategy` governs the entire tree. A `FailStrategy` declared on a `PipelineDefinition` wrapped via `nest()` is structurally present but **ignored** at execution time.

Lifecycle hooks (`beforeEachHooks`, `afterEachHooks`, `onStepFailedHooks`) on the outer pipeline fire **for every sub step** in every sub pipeline, exactly as they do for outer steps. Hooks declared on a wrapped sub pipeline are ignored.

Pipeline level callbacks (`onSuccessCallback`, `onFailureCallback`, `onCompleteCallback`) only fire when the **outer pipeline terminates**, exactly once.

## Compensation

Each inner sub step may declare its own compensation via `Step::make(Sub::class)->compensateWith(Rollback::class)`. When a sub step fails under `StopAndCompensate`, the compensation chain walks all completed steps (outer or inner) in reverse order.

The compensation map (`compensationMapping`) is built by recursively traversing the outer pipeline including sub pipelines. The saga invariant holds : any step that actually ran has its compensation declared at build time, available in the flat map.

## Nesting constraints

Sub pipelines accept all builder constructs : flat steps, conditions, parallel groups, other sub pipelines, conditional branches. Nesting is recursive without a hard coded depth limit.

The only restrictions come from parallel groups (see [parallel-steps.md](parallel-steps.md)), which reject `NestedPipeline` and `ConditionalBranch` entries in their sub steps.

## Testing

`PipelineFake` records sub pipelines in the recorded definition. The dedicated assertion is `assertNestedPipelineExecuted(array $expectedInnerClasses, ?string $name = null, ?int $pipelineIndex = null)` :

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::fake();

Pipeline::make([
    ValidateRequest::class,
    JobPipeline::nest(
        JobPipeline::make([LoadProfile::class, EnrichData::class]),
        name: 'enrichment',
    ),
    FormatResponse::class,
])->send($context)->run();

Pipeline::assertNestedPipelineExecuted(
    [LoadProfile::class, EnrichData::class],
    name: 'enrichment',
);
```

The assertion verifies that a sub pipeline with the expected name and classes was recorded. To inspect actual execution order and context state, use recording mode (`Pipeline::fake()->recording()`), which runs the steps and captures `executedSteps` / `contextSnapshots`.
