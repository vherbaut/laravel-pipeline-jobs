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
- **Parallel execution and branching.** Fan out / fan in groups (`JobPipeline::parallel`), nested sub pipelines (`JobPipeline::nest`), conditional branches (`Step::branch`).
- **Ecosystem integration.** Opt in Laravel events, `reverse()` for symmetrical rollbacks, rate limiting and concurrency gates, three accepted step shapes (`handle()`, middleware `handle($passable, Closure $next)`, invokable `__invoke()`).
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

### Optional: enable parallel step groups

Parallel step groups (`JobPipeline::parallel([...])`) fan out each sub step through Laravel's `Bus::batch()`, which requires the `job_batches` table. If you plan to use parallel groups on queued pipelines, run Laravel's built in command once to publish and apply the migration:

```bash
php artisan queue:batches-table
php artisan migrate
```

You can skip this step if you never dispatch parallel groups, or if you only run them synchronously (sync pipelines do not touch `Bus::batch()`).

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

For the full walkthrough (context design, queued mode, compensation, hooks, testing), see [docs/en/getting-started.md](docs/en/getting-started.md).

## Ecosystem Integration Example

The following example wires four integration features into a single pipeline. A tenant scoped order fulfillment chain that mixes a classic `handle()` step, a middleware style audit step, and an invokable Action step, gated by rate limit and concurrency, observable through Laravel events.

```php
use Closure;
use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\JobPipeline;

// 1. Typed context carrying the tenant id and the order payload.
final class OrderContext extends PipelineContext
{
    public ?Invoice $invoice = null;

    public function __construct(
        public readonly int $tenantId,
        public readonly Order $order,
    ) {}
}

// 2. Classic handle() step with InteractsWithPipeline for context access.
final class ValidateOrder
{
    use InteractsWithPipeline;

    public function handle(OrderValidator $validator): void
    {
        $validator->validate($this->pipelineContext()->order);
    }
}

// 3. Middleware style audit step. Uses $passable + Closure $next.
final class AuditStep
{
    public function handle(?OrderContext $passable, Closure $next): mixed
    {
        logger()->info('pipeline step started', ['tenant' => $passable?->tenantId]);
        $result = $next($passable);
        logger()->info('pipeline step finished', ['tenant' => $passable?->tenantId]);

        return $result;
    }
}

// 4. Action step. Invoked via __invoke(?PipelineContext $context).
final class NotifyCustomer
{
    public function __invoke(?OrderContext $context): void
    {
        if ($context?->invoice !== null) {
            Notification::send($context->order->customer, new OrderConfirmation($context->invoice));
        }
    }
}

// 5. Observe events in a service provider (once per boot).
Event::listen(PipelineStepCompleted::class, fn ($event) => metrics()->increment('pipeline.step.ok', ['class' => $event->stepClass]));
Event::listen(PipelineStepFailed::class,    fn ($event) => report($event->exception));
Event::listen(PipelineCompleted::class,     fn ($event) => metrics()->increment('pipeline.run.done'));

// 6. Compose the pipeline. All integration features at once.
$result = JobPipeline::make([
    ValidateOrder::class,
    AuditStep::class,
    ChargeCustomer::class,
    NotifyCustomer::class,
])
    ->rateLimit(
        fn (?OrderContext $ctx) => 'orders:tenant:'.$ctx->tenantId,
        max: 10,
        perSeconds: 60,
    )                                                  // Per tenant quota.
    ->maxConcurrent(
        fn (?OrderContext $ctx) => 'orders:tenant:'.$ctx->tenantId,
        limit: 3,
    )                                                  // Per tenant concurrency.
    ->dispatchEvents()                                 // Opt in observability.
    ->shouldBeQueued()
    ->send(new OrderContext(tenantId: $tenant->id, order: $order))
    ->run();

// Need to replay the same steps backwards for a tenant wide unwind?
// JobPipeline::make([...])->reverse()->send(...)->run();
```

The pipeline mixes three step shapes (classic, middleware, action) transparently. Rate limit and concurrency throw `PipelineThrottled` / `PipelineConcurrencyLimitExceeded` before any step runs when saturated. Events flow through Laravel's dispatcher and can be observed, queued, or batched by any listener.

See the dedicated docs for each feature:

- [Pipeline events](docs/en/pipeline-events.md).
- [Reverse pipelines](docs/en/reverse-pipelines.md).
- [Rate limiting and concurrency](docs/en/rate-limiting-concurrency.md).
- [Alternative step interfaces](docs/en/alternative-step-interfaces.md).

## Documentation

English documentation lives under [`docs/en/`](docs/en/). French documentation lives under [`docs/fr/`](docs/fr/).

| Topic | Description | Link |
|-------|-------------|------|
| Getting Started | Install the package, write your first typed context, run a pipeline, pass data between steps. | [docs/en/getting-started.md](docs/en/getting-started.md) |
| Core Concepts | `PipelineContext`, `PipelineBuilder` (array vs fluent), sync and queued execution modes. | [docs/en/core-concepts.md](docs/en/core-concepts.md) |
| Pipeline Aware Jobs | Wire a job to the shared context via the `InteractsWithPipeline` trait or an explicit property. Dual mode jobs. | [docs/en/pipeline-aware-jobs.md](docs/en/pipeline-aware-jobs.md) |
| Return Values | Transform the final context into a scalar value with `->return(Closure)`. | [docs/en/return-values.md](docs/en/return-values.md) |
| Conditional Steps | Branch execution with `when()` / `unless()` predicates evaluated against the live context. | [docs/en/conditional-steps.md](docs/en/conditional-steps.md) |
| Queued Pipelines | Run pipelines through Laravel's queue system. Serialization, retries, worker affinity. | [docs/en/queued-pipelines.md](docs/en/queued-pipelines.md) |
| Event Listener Bridge | Register a pipeline as an event listener with `JobPipeline::listen()` or `toListener()`. | [docs/en/event-listener-bridge.md](docs/en/event-listener-bridge.md) |
| Saga Compensation | Rollback with `compensateWith()`, `FailStrategy` policies, `CompensableJob` contract, failure observability. | [docs/en/saga-compensation.md](docs/en/saga-compensation.md) |
| Lifecycle Hooks | Per-step hooks (`beforeEach`, `afterEach`, `onStepFailed`) and pipeline-level callbacks (`onSuccess`, `onFailure(Closure)`, `onComplete`). | [docs/en/lifecycle-hooks.md](docs/en/lifecycle-hooks.md) |
| Per-Step Configuration | Route each step to its own queue or connection, override sync execution, set retry, backoff, and timeout policies per step, with pipeline-level defaults. | [docs/en/per-step-configuration.md](docs/en/per-step-configuration.md) |
| Dispatch Verb | Execute a pipeline with `Pipeline::dispatch([...])` as a Bus::dispatch-style alternative to `->make()->run()`. Auto-runs on destruct. | [docs/en/dispatch-verb.md](docs/en/dispatch-verb.md) |
| Parallel Step Groups | Fan out / fan in groups via `JobPipeline::parallel([...])`, `Bus::batch()` dispatch on the queue, context merging, nesting constraints. | [docs/en/parallel-steps.md](docs/en/parallel-steps.md) |
| Pipeline Nesting | Reuse sub pipelines via `JobPipeline::nest(...)`, nested cursor for queued mode, outer FailStrategy inheritance, inner defaults governance. | [docs/en/pipeline-nesting.md](docs/en/pipeline-nesting.md) |
| Conditional Branching | Pick a branch at runtime via `Step::branch($selector, [...])`, accepted branch values (class string, StepDefinition, sub pipeline), convergence on the next outer step. | [docs/en/conditional-branching.md](docs/en/conditional-branching.md) |
| Pipeline Events | Opt in Laravel events at three lifecycle points (`PipelineStepCompleted`, `PipelineStepFailed`, `PipelineCompleted`), correlate by `pipelineId`, queued listener caveat. | [docs/en/pipeline-events.md](docs/en/pipeline-events.md) |
| Reverse Pipelines | `PipelineBuilder::reverse()` for outer position reversal, inner structure preservation, full pipeline state copy, compensation interaction. | [docs/en/reverse-pipelines.md](docs/en/reverse-pipelines.md) |
| Rate Limiting and Concurrency | Pipeline level admission gates via `rateLimit()` and `maxConcurrent()`, Closure based keys, Cache driver requirements, composition with events. | [docs/en/rate-limiting-concurrency.md](docs/en/rate-limiting-concurrency.md) |
| Alternative Step Interfaces | Three accepted step shapes (`handle()`, middleware `handle($passable, Closure $next)`, invokable `__invoke()`), parameter naming contract, `InteractsWithPipeline` trait compatibility. | [docs/en/alternative-step-interfaces.md](docs/en/alternative-step-interfaces.md) |
| Testing | `Pipeline::fake()`, recording mode, step and context assertions, compensation assertions. | [docs/en/testing.md](docs/en/testing.md) |
| API Reference | Complete catalog of public symbols, methods, properties, exceptions, and events. | [docs/en/api-reference.md](docs/en/api-reference.md) |

## Roadmap

The following features are planned for future releases. The properties are already reserved in the codebase:

- **Top level named pipelines.** A `name('order-fulfillment')` to tag the whole pipeline (parallel groups, sub pipelines, and branches already expose an optional `name`).
- **Middleware and Action compensation.** Extend the strategy aware dispatcher to compensation paths so middleware shape and Action shape classes can serve as compensation targets (current contract requires classic `handle()` or `CompensableJob`).

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
