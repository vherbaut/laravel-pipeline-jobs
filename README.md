# Laravel Pipeline Jobs

A Laravel package for building **job pipelines with typed context** and **saga pattern support**.

Laravel's `Bus::chain()` is great for running jobs in sequence, but it treats each job as a black box. There is no built in way to pass data between steps, no compensation mechanism when things go wrong, and wiring event listeners to job chains requires boilerplate listener classes.

This package solves all three problems with a clean, fluent API that feels right at home in a Laravel application.

## Table of Contents

- [Why This Package?](#why-this-package)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Core Concepts](#core-concepts)
  - [Pipeline Context](#pipeline-context)
  - [Pipeline Builder](#pipeline-builder)
  - [Execution Modes](#execution-modes)
- [Usage](#usage)
  - [Building a Simple Pipeline](#building-a-simple-pipeline)
  - [Passing Data Between Steps](#passing-data-between-steps)
  - [Pipeline Aware Jobs](#pipeline-aware-jobs)
  - [Extracting Return Values](#extracting-return-values)
  - [Conditional Steps](#conditional-steps)
  - [Queued Pipelines](#queued-pipelines)
  - [Event Listener Bridge](#event-listener-bridge)
  - [Saga Pattern (Compensation)](#saga-pattern-compensation)
- [Testing](#testing)
  - [Fake Mode](#fake-mode)
  - [Recording Mode](#recording-mode)
  - [Available Assertions](#available-assertions)
  - [Context Snapshots](#context-snapshots)
  - [Compensation Assertions](#compensation-assertions)
- [API Reference](#api-reference)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)

## Why This Package?

Consider a typical order processing flow. You need to validate the order, charge the customer, reserve inventory, and send a confirmation email. Each step depends on data produced by the previous one.

With `Bus::chain()`, you would need to persist intermediate results to the database or cache, and retrieve them in each subsequent job. That is a lot of plumbing for what should be a simple data flow.

With Laravel Pipeline Jobs, each step receives and enriches a shared, typed context object:

```php
$context = new OrderContext(order: $order);

JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    ReserveInventory::class,
    SendConfirmation::class,
])
    ->send($context)
    ->run();

// After execution, $context->invoice, $context->shipment, etc. are all populated.
```

If `ChargeCustomer` fails, you can automatically compensate by running `RefundCustomer` and any other rollback steps, in the correct reverse order. That is the saga pattern, built right into the pipeline.

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 11.x, 12.x, 13.x |

## Installation

```bash
composer require vherbaut/laravel-pipeline-jobs
```

The package auto discovers its service provider and facade. No manual registration is needed.

## Quick Start

**1. Create a context class** that carries data through your pipeline:

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class OrderContext extends PipelineContext
{
    public ?Invoice $invoice = null;
    public ?Shipment $shipment = null;
    public string $status = 'pending';

    public function __construct(
        public Order $order,
    ) {}
}
```

**2. Create your job steps.** Pull in the `InteractsWithPipeline` trait and read the context through `pipelineContext()`:

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class ChargeCustomer
{
    use InteractsWithPipeline;

    public function handle(PaymentService $payments): void
    {
        $context = $this->pipelineContext();

        $context->invoice = $payments->charge($context->order);
        $context->status = 'charged';
    }
}
```

Notice how `PaymentService` is injected via Laravel's container, just like any other job. The pipeline executor injects the live context onto the job automatically, via the trait. If you prefer an explicit dependency, you can declare a `public PipelineManifest $pipelineManifest;` property instead of using the trait. Both patterns are fully supported (see the [Pipeline Aware Jobs](#pipeline-aware-jobs) section).

**3. Run the pipeline:**

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

$context = new OrderContext(order: $order);

$result = JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    ReserveInventory::class,
    SendConfirmation::class,
])
    ->send($context)
    ->run();

// $result is the final OrderContext with all steps applied.
```

That's it. Four lines of code replace dozens of lines of chain boilerplate, cache juggling, and manual coordination.

## Core Concepts

### Pipeline Context

The `PipelineContext` class is the foundation of data flow in your pipelines. It is a simple DTO (Data Transfer Object) that travels through each step, accumulating state along the way.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class MyContext extends PipelineContext
{
    public ?Model $result = null;
    public array $metadata = [];
    public string $status = 'pending';
}
```

**Key characteristics:**

- **Typed properties.** Use PHP's type system to define exactly what data flows through your pipeline. Each step knows what it can read and write.
- **Eloquent model support.** The base class uses Laravel's `SerializesModels` trait, so Eloquent models are properly serialized when pipelines are queued.
- **Serialization validation.** Before dispatching a queued pipeline, the context is validated to ensure all properties are serializable. Closures, resources, and anonymous classes are rejected immediately with a clear error message, rather than failing silently in the queue worker.

### Pipeline Builder

The builder provides a fluent API for constructing pipelines. There are two equivalent syntaxes:

**Array API** (concise, great for simple pipelines):

```php
JobPipeline::make([
    StepA::class,
    StepB::class,
    StepC::class,
]);
```

**Fluent API** (allows per step configuration like compensation):

```php
JobPipeline::make()
    ->step(StepA::class)
    ->step(StepB::class)->compensateWith(UndoStepB::class)
    ->step(StepC::class)->compensateWith(UndoStepC::class);
```

Both produce the same immutable `PipelineDefinition`. Choose whichever reads best for your use case.

### Execution Modes

Pipelines support two execution modes:

**Synchronous** (default). Steps execute one after another in the current process. The final context is returned directly:

```php
$result = JobPipeline::make([...])
    ->send($context)
    ->run(); // Returns PipelineContext
```

**Queued**. Steps are dispatched to Laravel's queue system. Each step is wrapped in an internal job that, upon completion, dispatches the next step. This means steps run on potentially different workers, with the full pipeline state serialized in each job payload:

```php
JobPipeline::make([...])
    ->send($context)
    ->shouldBeQueued()
    ->run(); // Returns null (execution is async)
```

The queued executor validates context serialization **before** dispatching. If your context contains a closure or a resource, you will get an immediate `ContextSerializationFailed` exception rather than a mysterious queue failure minutes later.

## Usage

### Building a Simple Pipeline

The simplest pipeline is a list of jobs that execute in order:

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    GenerateReport::class,
    SendReportEmail::class,
    ArchiveReport::class,
])->run();
```

Without a context (`send()` not called), jobs simply execute in sequence. This is useful when your jobs communicate through the database or don't need shared state.

### Passing Data Between Steps

The real power of this package is the typed context that flows between steps. Here is a complete example:

```php
// 1. Define your context
class ImportContext extends PipelineContext
{
    public array $rows = [];
    public int $imported = 0;
    public array $errors = [];

    public function __construct(
        public string $filePath,
    ) {}
}

// 2. Define your steps
class ParseCsvFile
{
    public PipelineManifest $pipelineManifest;

    public function handle(): void
    {
        $context = $this->pipelineManifest->context;
        $context->rows = CsvParser::parse($context->filePath);
    }
}

class ValidateRows
{
    public PipelineManifest $pipelineManifest;

    public function handle(RowValidator $validator): void
    {
        $context = $this->pipelineManifest->context;

        foreach ($context->rows as $index => $row) {
            if (! $validator->isValid($row)) {
                $context->errors[] = "Row {$index} is invalid";
            }
        }
    }
}

class ImportValidRows
{
    public PipelineManifest $pipelineManifest;

    public function handle(): void
    {
        $context = $this->pipelineManifest->context;

        $validRows = array_filter($context->rows, fn ($row, $i) =>
            ! in_array("Row {$i} is invalid", $context->errors),
            ARRAY_FILTER_USE_BOTH
        );

        $context->imported = count($validRows);
        // ... persist valid rows
    }
}

// 3. Run the pipeline
$result = JobPipeline::make([
    ParseCsvFile::class,
    ValidateRows::class,
    ImportValidRows::class,
])
    ->send(new ImportContext(filePath: '/tmp/data.csv'))
    ->run();

echo "Imported {$result->imported} rows with " . count($result->errors) . " errors.";
```

Each step reads from and writes to the same context object. The context is a plain PHP object, so your IDE provides full autocompletion and type checking.

### Pipeline Aware Jobs

Every step in a pipeline needs to read or write the shared context. The package offers two equivalent ways to wire that up, and you can mix them freely across your codebase.

**1. The `InteractsWithPipeline` trait (recommended).** Drop the trait into any job class and you get two accessors for free:

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class SendWelcomeEmail
{
    use InteractsWithPipeline;

    public function handle(Mailer $mailer): void
    {
        $user = $this->pipelineContext()->user;

        $mailer->send(new WelcomeMail($user));
    }
}
```

| Accessor | Returns | Description |
|----------|---------|-------------|
| `pipelineContext()` | `?PipelineContext` | The live context when running inside a pipeline, `null` otherwise. |
| `hasPipelineContext()` | `bool` | Whether a non null context is currently available. |

**2. Explicit property.** If you prefer a fully visible dependency (for example, to type hint a custom context subclass), declare the manifest as a public property:

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

class SendWelcomeEmail
{
    public PipelineManifest $pipelineManifest;

    public function handle(Mailer $mailer): void
    {
        $user = $this->pipelineManifest->context->user;

        $mailer->send(new WelcomeMail($user));
    }
}
```

Both patterns produce identical runtime behaviour. The trait is less boilerplate, the explicit property is more discoverable. Pick whichever your team prefers.

**Dual mode jobs.** The trait shines when you want the same job to run both standalone (via `Bus::dispatch`) and inside a pipeline. Use `hasPipelineContext()` to branch:

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class SyncProduct
{
    use InteractsWithPipeline;

    public function __construct(
        public readonly int $productId,
    ) {}

    public function handle(ProductSyncService $sync): void
    {
        if ($this->hasPipelineContext()) {
            // Pipeline mode: pull the product from the shared context.
            $sync->push($this->pipelineContext()->product);

            return;
        }

        // Standalone mode: load the product from storage.
        $sync->push(Product::findOrFail($this->productId));
    }
}
```

**How it works under the hood.** The pipeline executors (`SyncExecutor`, `PipelineStepJob`, `RecordingExecutor`) look for a `pipelineManifest` property on every step they run, via `property_exists()` and `ReflectionProperty::setValue()`. The trait declares that property for you. When a job runs outside a pipeline, no executor touches the property, so it stays at its `null` default and both accessors return "not in a pipeline" values.

### Extracting Return Values

By default, `->run()` returns the full `PipelineContext` after synchronous execution. When you only care about a single field (a total, an invoice ID, a computed result), the `->return()` method lets you declare a closure that transforms the final context into the value you actually want.

```php
$total = JobPipeline::make([
    CreateOrder::class,
    ApplyDiscount::class,
    CalculateTotal::class,
])
    ->send(new OrderContext(items: $items))
    ->return(fn (OrderContext $ctx) => $ctx->total)
    ->run();

// $total is the scalar computed by CalculateTotal. No manual dereferencing.
```

**Behaviour notes:**

- **Sync only.** The closure runs exclusively in synchronous mode. Queued runs always return `null` because execution is deferred to workers and the closure is never invoked.
- **Null argument.** When no context was sent via `->send()`, the closure is still called with `null` as its argument. Your closure is responsible for handling the null case.
- **Last write wins.** Calling `->return()` multiple times silently overrides the previous closure. Only the most recent registration is applied.
- **Exceptions propagate verbatim.** If your return closure throws, the exception bubbles out of `->run()` unchanged. It is NOT wrapped in `StepExecutionFailed` because the closure runs after the executor, not as a step.

**Without `->return()`:**

```php
$context = JobPipeline::make([CreateOrder::class, CalculateTotal::class])
    ->send(new OrderContext(items: $items))
    ->run();

return $context->total; // Manual dereferencing, return type widens to ?PipelineContext
```

**With `->return()`:**

```php
return JobPipeline::make([CreateOrder::class, CalculateTotal::class])
    ->send(new OrderContext(items: $items))
    ->return(fn (OrderContext $ctx) => $ctx->total)
    ->run();
```

### Conditional Steps

Some steps should only run under specific runtime conditions (a feature flag, a context value set by an earlier step, a user preference). The builder exposes `when()` and `unless()` for this:

```php
JobPipeline::make()
    ->step(ValidateOrder::class)
    ->when(
        fn (OrderContext $ctx) => $ctx->order->requiresApproval,
        NotifyManager::class,
    )
    ->step(ChargeCustomer::class)
    ->unless(
        fn (OrderContext $ctx) => $ctx->order->isDigital,
        ShipPackage::class,
    )
    ->send(new OrderContext(order: $order))
    ->run();
```

- `when(Closure $condition, string $jobClass)` appends a step that runs only when the closure returns truthy.
- `unless(Closure $condition, string $jobClass)` is the inverse. The step runs only when the closure returns falsy.

**Runtime evaluation.** Conditions are evaluated against the live `PipelineContext` immediately before the step would execute, in both synchronous and queued modes. Earlier steps can mutate the context and later conditions see the updated state:

```php
JobPipeline::make()
    ->step(LoadOrder::class) // populates $ctx->order
    ->when(
        fn (OrderContext $ctx) => $ctx->order->status === 'pending',
        SendReminderEmail::class, // only runs when the loaded order is pending
    )
    ->send(new OrderContext(orderId: $id))
    ->run();
```

**Queued pipelines.** Condition closures must be serializable because they travel with the manifest. The builder wraps them with `SerializableClosure` automatically, so any variables captured through `use` must be serializable too. Avoid capturing resources, anonymous classes, or live database handles inside a condition. When the predicate depends on external state, load that state in a preceding step and read it from the context instead.

**Composing with compensation.** A conditional step can still register a compensation job:

```php
JobPipeline::make()
    ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
    ->when(
        fn (OrderContext $ctx) => $ctx->order->total > 100,
        ChargeCustomer::class,
    )->compensateWith(RefundCustomer::class)
    ->send(new OrderContext(order: $order))
    ->run();
```

If the conditional step is skipped (the closure returned falsy), its compensation never runs, even if a later step fails. Compensation only applies to steps that actually executed.

### Queued Pipelines

To dispatch a pipeline to the queue, add `shouldBeQueued()`:

```php
JobPipeline::make([
    ProcessVideo::class,
    GenerateThumbnails::class,
    NotifyUser::class,
])
    ->send(new VideoContext(video: $video))
    ->shouldBeQueued()
    ->run();
```

**How it works under the hood:**

1. The context is validated for serializability (fail fast).
2. The first step is wrapped in a `PipelineStepJob` and dispatched to the queue.
3. When the first step completes, the next `PipelineStepJob` is dispatched automatically.
4. This continues until all steps have executed.

Each queued job carries the complete pipeline manifest (context, step list, progress). This means any worker can pick up any step, and there is no external state to manage.

**Important note:** Queued pipeline steps use `tries = 1` by default. This prevents the re execution of already completed steps after a worker crash. If you need retries, implement retry logic within each individual job.

### Event Listener Bridge

One of the most common Laravel patterns is dispatching jobs in response to events. Normally this requires creating a dedicated listener class for each event/job combination. This package eliminates that boilerplate.

**One liner registration** in your service provider:

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        JobPipeline::listen(
            OrderPlaced::class,
            [ProcessOrder::class, SendReceipt::class, UpdateAnalytics::class],
            fn (OrderPlaced $event) => new OrderContext(order: $event->order),
        );
    }
}
```

The third argument is a closure that receives the event and returns a `PipelineContext`. This is how you bridge event data into the pipeline.

**Alternative syntax** using `toListener()` when you need more control:

```php
$listener = JobPipeline::make([
    ProcessOrder::class,
    SendReceipt::class,
])
    ->send(fn (OrderPlaced $event) => new OrderContext(order: $event->order))
    ->toListener();

Event::listen(OrderPlaced::class, $listener);
```

Both approaches are equivalent. The closure form (`send(fn ($event) => ...)`) is preferred because it defers context creation to the moment the event actually fires, rather than creating it eagerly.

### Saga Pattern (Compensation)

In distributed systems, when a multi step process fails partway through, you often need to undo the steps that already completed. This is the **saga pattern**, and it is built directly into the pipeline builder.

```php
JobPipeline::make()
    ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
    ->step(ChargeCustomer::class)->compensateWith(RefundCustomer::class)
    ->step(CreateShipment::class)->compensateWith(CancelShipment::class)
    ->send(new OrderContext(order: $order))
    ->run();
```

**How compensation works:**

1. Steps execute in order: `ReserveInventory`, then `ChargeCustomer`, then `CreateShipment`.
2. If `CreateShipment` throws an exception, compensation kicks in.
3. Only the **completed** steps are compensated, in **reverse order**: `RefundCustomer` first, then `ReleaseInventory`.
4. `CancelShipment` is **not** called because `CreateShipment` never completed.

Compensation jobs receive the same pipeline manifest (with context) as regular steps. This means they have access to all the data accumulated by the steps that did complete, which they need to perform the rollback.

**A compensation job looks just like a regular step:**

```php
class RefundCustomer
{
    public PipelineManifest $pipelineManifest;

    public function handle(PaymentService $payments): void
    {
        $context = $this->pipelineManifest->context;
        $payments->refund($context->invoice);
    }
}
```

If a compensation job itself throws an exception, it is swallowed (logged but not rethrown). The remaining compensation jobs continue to execute. This ensures that a failure in one rollback step does not prevent other rollbacks from running.

## Testing

This package provides a comprehensive testing toolkit that follows the same patterns as Laravel's `Bus::fake()` and `Queue::fake()`. You get a test double, assertion methods, and execution recording, all accessible through the `Pipeline` facade.

### Fake Mode

Fake mode intercepts all pipeline executions without actually running any steps. This is useful when you want to verify that a pipeline was dispatched with the correct configuration, without caring about step execution.

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

### Recording Mode

Recording mode goes further: it actually executes your pipeline steps (synchronously) while capturing a full execution trace. This lets you verify not just that a pipeline was dispatched, but that each step executed correctly and modified the context as expected.

Enable recording mode by calling `recording()` on the fake:

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

    // Verify step execution
    $fake->assertStepExecuted(ValidateOrder::class);
    $fake->assertStepExecuted(ChargeCustomer::class);
    $fake->assertStepExecuted(CreateShipment::class);

    // Verify execution order
    $fake->assertStepsExecutedInOrder([
        ValidateOrder::class,
        ChargeCustomer::class,
        CreateShipment::class,
    ]);

    // Verify final context state
    $fake->assertContextHas('status', 'shipped');
});
```

### Available Assertions

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

### Context Snapshots

One of the most powerful testing features is the ability to inspect the context at any point during execution. After each step completes, the recording executor takes a deep clone of the context. You can retrieve these snapshots to verify intermediate state:

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

This is invaluable for verifying that each step does its part correctly, independently of other steps.

### Compensation Assertions

When testing saga patterns, you need to verify that the right compensation jobs run (or don't run) when failures occur:

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

    // Compensation was triggered
    $fake->assertCompensationWasTriggered();

    // Completed steps were compensated in reverse order
    $fake->assertCompensationRan(RefundCustomer::class);
    $fake->assertCompensationRan(ReleaseInventory::class);

    // The failing step's compensation was NOT run (it never completed)
    $fake->assertCompensationNotRan(UndoFailingStep::class);

    // Verify compensation order
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

## API Reference

### `JobPipeline`

| Method | Returns | Description |
|--------|---------|-------------|
| `make(array $jobs = [])` | `PipelineBuilder` | Create a new pipeline builder, optionally with an array of job classes. |
| `listen(string $event, array $jobs, ?Closure $send)` | `void` | Register a pipeline as an event listener in a single call. |

### `PipelineBuilder`

| Method | Returns | Description |
|--------|---------|-------------|
| `step(string $jobClass)` | `static` | Add a step to the pipeline. |
| `when(Closure $condition, string $jobClass)` | `static` | Append a step that runs only when the condition (evaluated against the live context) returns truthy. Condition must be serializable in queued mode. |
| `unless(Closure $condition, string $jobClass)` | `static` | Append a step that runs only when the condition (evaluated against the live context) returns falsy. Condition must be serializable in queued mode. |
| `compensateWith(string $jobClass)` | `static` | Assign a compensation job to the last added step. |
| `send(PipelineContext\|Closure $context)` | `static` | Set the context (instance or closure for deferred resolution). |
| `shouldBeQueued()` | `static` | Mark the pipeline for asynchronous queue execution. |
| `return(Closure $callback)` | `static` | Register a closure that transforms the final context into the value returned by `run()`. Synchronous only. Ignored in queued mode. |
| `build()` | `PipelineDefinition` | Build an immutable pipeline definition from the current builder state. |
| `run()` | `mixed` | Build and execute the pipeline. Returns the `->return()` closure result when registered, otherwise the final `PipelineContext` (or `null`). Always `null` in queued mode. |
| `toListener()` | `Closure` | Convert the pipeline to an event listener closure. |
| `getContext()` | `PipelineContext\|Closure\|null` | Retrieve the currently configured context. |

### `InteractsWithPipeline` trait

| Method | Returns | Description |
|--------|---------|-------------|
| `pipelineContext()` | `?PipelineContext` | The live `PipelineContext` when the job runs inside a pipeline with a context, `null` otherwise. |
| `hasPipelineContext()` | `bool` | `true` when a non null context is currently available, `false` for standalone dispatch or pipelines without `->send(...)`. |

### `PipelineContext`

| Method | Returns | Description |
|--------|---------|-------------|
| `validateSerializable()` | `void` | Validate that all properties can be serialized for queue dispatch. |

### `PipelineManifest`

| Property | Type | Description |
|----------|------|-------------|
| `pipelineId` | `string` | Unique identifier (UUID) for this pipeline execution. |
| `stepClasses` | `array<int, string>` | Ordered list of step class names. |
| `compensationMapping` | `array<string, string>` | Map of step class to compensation class. |
| `currentStepIndex` | `int` | Index of the currently executing step. |
| `completedSteps` | `array<int, string>` | Steps that have completed successfully. |
| `context` | `?PipelineContext` | The shared context object. |

### `Pipeline` Facade

The `Pipeline` facade proxies to `JobPipeline` and adds the `fake()` method for testing:

| Method | Returns | Description |
|--------|---------|-------------|
| `fake()` | `PipelineFake` | Replace the pipeline system with a test double. |

### Exceptions

| Exception | When |
|-----------|------|
| `InvalidPipelineDefinition` | Pipeline has no steps, or `compensateWith()` called before any step. |
| `StepExecutionFailed` | A step threw an exception during synchronous execution. Wraps the original exception. |
| `ContextSerializationFailed` | Context contains non serializable properties (closures, resources, anonymous classes). |

## Roadmap

The following features are planned for future releases. The properties are already reserved in the codebase:

- **Per step queue configuration.** Set queue name, connection, retry count, backoff, and timeout per step.
- **Pipeline lifecycle hooks.** `beforeEach()`, `afterEach()`, `onStepFailed()`, `onSuccess()`, `onFailure()`, `onComplete()`.
- **Named pipelines.** `name('order-fulfillment')` for better observability and logging.
- **Parallel steps.** Fan out pattern for steps that can execute concurrently.
- **Pipeline events.** Dispatch Laravel events at key lifecycle points.

## Contributing

Contributions are welcome! Please see the following commands to get started:

```bash
# Run the test suite
composer test

# Run static analysis
composer analyse

# Format code
composer format
```

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.
