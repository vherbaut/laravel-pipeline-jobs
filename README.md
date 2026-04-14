# Laravel Pipeline Jobs

> Une documentation en français est disponible dans [README-fr.md](README-fr.md).

A Laravel package for building **job pipelines with typed context** and **saga pattern support**.

Laravel's `Bus::chain()` is great for running jobs in sequence, but it treats each job as a black box. There is no built in way to pass data between steps, no compensation mechanism when things go wrong, and wiring event listeners to job chains requires boilerplate listener classes.

This package solves all three problems with a clean, fluent API that feels right at home in a Laravel application.

## Table of Contents

- [Why This Package?](#why-this-package)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Documentation](#documentation)
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

Key features at a glance:

- **Typed context.** A shared DTO flows through every step, with full IDE autocompletion and static analysis support.
- **Sync and queued execution.** Flip a single call (`shouldBeQueued()`) to move a pipeline between modes with no code changes.
- **Saga compensation.** Declarative rollback with `compensateWith()` plus three `FailStrategy` policies.
- **Conditional steps.** `when()` / `unless()` predicates evaluated against the live context.
- **Lifecycle hooks and observability.** Six hooks (per-step and pipeline-level) for logging, metrics, and alerting.
- **Event listener bridge.** One line to register a pipeline as a listener.
- **Comprehensive testing toolkit.** `Pipeline::fake()`, recording mode, context snapshots, compensation assertions.

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

**1. Define a typed context** that carries data through your pipeline:

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class OrderContext extends PipelineContext
{
    public ?Invoice $invoice = null;
    public string $status = 'pending';

    public function __construct(
        public Order $order,
    ) {}
}
```

**2. Write your job steps.** Add the `InteractsWithPipeline` trait to read the context:

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

**3. Run the pipeline:**

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

$result = JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    SendConfirmation::class,
])
    ->send(new OrderContext(order: $order))
    ->run();

// $result is the final OrderContext with all steps applied.
```

For the full walkthrough (context design, queued mode, compensation, hooks, testing), see [docs/getting-started.md](docs/getting-started.md).

## Documentation

| Topic | Description | Link |
|-------|-------------|------|
| Getting Started | Install the package, write your first typed context, run a pipeline, pass data between steps. | [docs/getting-started.md](docs/getting-started.md) |
| Core Concepts | `PipelineContext`, `PipelineBuilder` (array vs fluent), sync and queued execution modes. | [docs/core-concepts.md](docs/core-concepts.md) |
| Pipeline Aware Jobs | Wire a job to the shared context via the `InteractsWithPipeline` trait or an explicit property. Dual mode jobs. | [docs/pipeline-aware-jobs.md](docs/pipeline-aware-jobs.md) |
| Return Values | Transform the final context into a scalar value with `->return(Closure)`. | [docs/return-values.md](docs/return-values.md) |
| Conditional Steps | Branch execution with `when()` / `unless()` predicates evaluated against the live context. | [docs/conditional-steps.md](docs/conditional-steps.md) |
| Queued Pipelines | Run pipelines through Laravel's queue system. Serialization, retries, worker affinity. | [docs/queued-pipelines.md](docs/queued-pipelines.md) |
| Event Listener Bridge | Register a pipeline as an event listener with `JobPipeline::listen()` or `toListener()`. | [docs/event-listener-bridge.md](docs/event-listener-bridge.md) |
| Saga Compensation | Rollback with `compensateWith()`, `FailStrategy` policies, `CompensableJob` contract, failure observability. | [docs/saga-compensation.md](docs/saga-compensation.md) |
| Lifecycle Hooks | Per-step hooks (`beforeEach`, `afterEach`, `onStepFailed`) and pipeline-level callbacks (`onSuccess`, `onFailure(Closure)`, `onComplete`). | [docs/lifecycle-hooks.md](docs/lifecycle-hooks.md) |
| Testing | `Pipeline::fake()`, recording mode, step and context assertions, compensation assertions. | [docs/testing.md](docs/testing.md) |
| API Reference | Complete catalog of public symbols, methods, properties, exceptions, and events. | [docs/api-reference.md](docs/api-reference.md) |

## Roadmap

The following features are planned for future releases. The properties are already reserved in the codebase:

- **Per step queue configuration.** Set queue name, connection, retry count, backoff, and timeout per step.
- **Named pipelines.** `name('order-fulfillment')` for better observability and logging.
- **Parallel steps.** Fan out pattern for steps that can execute concurrently.
- **Pipeline events.** Dispatch Laravel events at key lifecycle points.

## Contributing

Contributions are welcome. To get started:

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
