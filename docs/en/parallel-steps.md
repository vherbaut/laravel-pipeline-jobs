# Parallel Step Groups

Some pipeline steps can run at the same time without depending on each other. Parallel groups model this **fan out / fan in** pattern. N sub steps dispatch simultaneously, and the pipeline waits for all of them to finish before moving on.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    ValidateOrder::class,
    JobPipeline::parallel([
        GenerateInvoicePdf::class,
        NotifyWarehouse::class,
        SendAnalyticsEvent::class,
    ]),
    FinalizeOrder::class,
])
    ->send(new OrderContext(order: $order))
    ->run();
```

The three sub steps (PDF generation, warehouse notification, analytics event) run in parallel after `ValidateOrder`. `FinalizeOrder` only starts once all three have completed.

## Table of Contents

- [Two ways to build a group](#two-ways-to-build-a-group)
- [Sync vs queued execution](#sync-vs-queued-execution)
- [Context merging](#context-merging)
- [Per sub step configuration](#per-sub-step-configuration)
- [Nesting constraints](#nesting-constraints)
- [Compensation and hooks](#compensation-and-hooks)
- [Payload size impact](#payload-size-impact)
- [Testing](#testing)

## Two ways to build a group

**Array API** (top to bottom reading) :

```php
JobPipeline::make([
    FirstStep::class,
    JobPipeline::parallel([SubA::class, SubB::class, SubC::class]),
    LastStep::class,
])->run();
```

**Fluent API** (chaining) :

```php
JobPipeline::make()
    ->step(FirstStep::class)
    ->parallel([SubA::class, SubB::class, SubC::class])
    ->step(LastStep::class)
    ->run();
```

Both forms produce exactly the same `PipelineDefinition`. Pick whichever fits your style.

A `ParallelStepGroup` takes **one outer position** in the pipeline. `stepCount()` counts it as 1. `flatStepCount()` expands it to the number of sub steps (useful to estimate queue job volume).

## Sync vs queued execution

**Sync mode.** Sub steps run sequentially within the same PHP process (no actual parallelism, but identical semantics : the context after the group is the aggregation of all runs). Works for tests, artisan commands, and API driven workflows.

**Queued mode.** The group dispatches via Laravel's `Bus::batch()`. Each sub step becomes an independent job handled by a worker, potentially on several threads in parallel. The batch `finally()` callback triggers the dispatch of the next outer step.

```php
JobPipeline::make([
    ValidateOrder::class,
    JobPipeline::parallel([GenerateInvoicePdf::class, NotifyWarehouse::class]),
    FinalizeOrder::class,
])
    ->shouldBeQueued()
    ->send($context)
    ->run();
```

Queued mode requires the `job_batches` table. If your project does not have it yet :

```bash
php artisan queue:batches-table
php artisan migrate
```

Sync pipelines can skip this step.

## Context merging

Each sub step in a group receives a **deep copy** of the context at fan out time. They can freely mutate their own snapshot without stepping on each other. Once all sub steps finish, the snapshots are **merged** into the outer context by `ParallelContextMerger`.

Merge rules (priority order) :

1. **Scalars and objects** : the last sub step to write wins (batch completion order).
2. **Arrays** : merged recursively via `array_merge` (numeric keys concatenate, string keys overwrite).
3. **`null` vs value** : a non null value always wins over `null`.

Sub steps should therefore avoid writing to the **same** context properties. If two sub steps both write `$context->total`, the result depends on a non deterministic completion order. To isolate writes, prefer dedicated properties per sub step (for example `$context->invoicePdfPath` and `$context->warehouseNotificationId`) that a subsequent step consumes.

## Per sub step configuration

Each sub step can carry its own configuration via a pre built `StepDefinition` :

```php
use Vherbaut\LaravelPipelineJobs\Step;

JobPipeline::parallel([
    Step::make(GenerateInvoicePdf::class)->onQueue('pdf')->timeout(120),
    Step::make(NotifyWarehouse::class)->onQueue('notifications')->retry(3)->backoff(5),
    SendAnalyticsEvent::class, // pipeline defaults apply
]);
```

Pipeline level defaults (`defaultQueue()`, `defaultRetry()`, etc.) apply to sub steps that do not override them. The mutators `compensateWith()`, `onQueue()`, `onConnection()`, `sync()`, `retry()`, `backoff()`, `timeout()` **cannot** be called immediately after `->parallel(...)` on the builder : they target a single step, but a parallel group aggregates several sub steps potentially with distinct configurations. Apply them to each sub step individually via `Step::make(...)->mutator(...)`.

## Nesting constraints

A parallel group accepts only **flat sub steps** (class string or `StepDefinition`). The following group compositions are rejected at build time :

| Attempt | Exception |
|---------|-----------|
| A `ParallelStepGroup` nested inside another | `InvalidPipelineDefinition::nestedParallelGroup()` |
| A `NestedPipeline` (sub pipeline) inside a parallel group | `InvalidPipelineDefinition::nestedPipelineInsideParallelGroup()` |
| A `ConditionalBranch` inside a parallel group | `InvalidPipelineDefinition::conditionalBranchInsideParallelGroup()` |

The reason is the per sub step deep clone of the manifest : these three mechanisms (parallel, nested, branch) each carry cursor and selection invariants that break under N way fan out.

To combine parallel with nested or branch, **wrap the parallel on the outside** or **use a sub pipeline** :

```php
// Correct : parallel around a sub pipeline
JobPipeline::parallel([
    JobPipeline::nest([StepA::class, StepB::class]),
    StepC::class,
]);
```

## Compensation and hooks

Each sub step can declare its own compensation via `Step::make(SubStep::class)->compensateWith(Rollback::class)`. When the pipeline runs under `FailStrategy::StopAndCompensate` and a sub step fails :

1. The **already completed** sub steps of the same group are recorded in `$manifest->completedSteps`.
2. After the failure, the compensation chain walks backwards over all completed outer steps **plus** the completed sub steps of the current group.
3. Sub steps that never got to start are not compensated.

Pipeline level lifecycle hooks (`beforeEachHooks`, `afterEachHooks`, `onStepFailedHooks`) fire **for every sub step** in the group. The `StepDefinition` passed to the hook is built on the fly via `StepDefinition::fromJobClass($subClass)`, so it does not carry a compensation or configuration.

## Payload size impact

The `Bus::batch()` dispatch creates N wrapper jobs, each carrying **its own copy** of the manifest. For a group of N sub steps, the batch payload therefore weighs N times the manifest size. This cost only applies during the batch lifetime (until the `finally()` dispatches the next outer step).

NFR11 caps SQS payloads at 256 KB per job. To stay under this limit, keep the context size moderate and prefer loading large blobs (files, documents) through a reference (database ID) rather than by value in the context.

## Testing

`PipelineFake` records parallel groups in the recorded definition. The dedicated assertion is `assertParallelGroupExecuted(array $expectedClasses, ?int $pipelineIndex = null)` :

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::fake();

Pipeline::make([
    FirstStep::class,
    JobPipeline::parallel([SubA::class, SubB::class]),
])->send($context)->run();

Pipeline::assertParallelGroupExecuted([SubA::class, SubB::class]);
```

The assertion checks that the recorded definition contains **at least one** `ParallelStepGroup` whose classes (in insertion order) match `$expectedClasses`. For real execution assertions (what actually ran, in which order), switch to recording mode via `Pipeline::fake()->recording()` and inspect `executedSteps` / `contextSnapshots`.
