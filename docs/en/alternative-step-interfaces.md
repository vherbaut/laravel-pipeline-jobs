# Alternative Step Interfaces

Pipeline steps can follow three shapes. The pipeline detects the shape at execution time through reflection and dispatches the call accordingly. This lets you reuse middleware style jobs (Laravel Pipeline convention, `lorisleiva/laravel-actions`, `Spatie\QueueableAction`) inside a pipeline without rewriting them.

## The three supported shapes

### 1. Default `handle()`

The classic Laravel queued job shape. This is the legacy contract and the default when `handle()` takes zero or one container resolvable parameters.

```php
class ValidateOrder
{
    public function handle(OrderValidator $validator): void
    {
        // classic job shape
    }
}
```

### 2. Middleware `handle($passable, Closure $next)`

The Laravel `Illuminate\Pipeline` middleware shape. Detected when `handle()` has **two or more parameters** and the **second parameter is typed exactly as `Closure`**.

```php
use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class AuditEveryStep
{
    public function handle(?PipelineContext $passable, Closure $next): mixed
    {
        logger()->info('before step', ['context' => $passable]);
        $result = $next($passable);
        logger()->info('after step', ['context' => $passable]);

        return $result;
    }
}
```

The pipeline binds `$passable` to the **live context** and passes an **identity closure** as `$next`. Calling `$next($passable)` returns `$passable` unchanged. Whether you call `$next()` or not, the pipeline always advances to the next step on return (pipeline ordering is manifest driven, not middleware chained).

### 3. Action `__invoke()`

The invokable Action shape. Detected when the class has **no `handle()` method** and defines `__invoke()`.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class SendWelcomeEmail
{
    public function __invoke(?PipelineContext $context): void
    {
        // invokable action shape
    }
}
```

The pipeline binds the context to the parameter named `$context`. You can also mix container resolved dependencies with the context binding.

```php
class SendWelcomeEmail
{
    public function __invoke(MailService $mail, ?PipelineContext $context): void
    {
        $mail->send($context->user);
    }
}
```

## Detection precedence

When a class defines **both** `handle()` and `__invoke()`, `handle()` wins. This keeps `__invoke()` harmless on classes that use the default shape and keeps `InteractsWithPipeline` users safe if they add `__invoke()` later.

The detection applies only to the second parameter of `handle()`. Union types (`Closure|string`) and intersection types fall through to the default shape.

## Parameter naming contract

The pipeline binds the resolved context by **parameter name** through Laravel's container.

| Shape | Expected parameter name |
|-------|-------------------------|
| Middleware | `$passable` (and `$next` for the closure) |
| Action | `$context` |

A user who names the middleware parameter something other than `$passable` (for example `$request`, `$ctx`, `$input`) **will not** receive the live pipeline context unless the parameter is typed as `?PipelineContext`. In that case Laravel's container resolves it by type, which may return a different instance than the one the pipeline actually carries. The safe path is to stick to the documented parameter names **or** use the `InteractsWithPipeline` trait for name independent access.

## Compatibility with `InteractsWithPipeline`

The trait works on all three shapes. Manifest injection happens before dispatch, so `$this->pipelineContext()` returns the same instance regardless of whether the step is `handle()`, middleware, or Action.

```php
use Closure;
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class MiddlewareWithTrait
{
    use InteractsWithPipeline;

    public function handle(mixed $passable, Closure $next): mixed
    {
        // $this->pipelineContext() === $passable when context is non null
        return $next($passable);
    }
}
```

For Action shapes, the trait is the cleanest way to access the context without declaring a parameter.

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class ActionWithTrait
{
    use InteractsWithPipeline;

    public function __invoke(): void
    {
        $this->pipelineContext()->someProperty = 'value';
    }
}
```

## Compensation note

Compensation paths (`CompensableJob::compensate()` and the `handle()` fallback) still follow the classic compensation contract. Middleware shape and Action shape **compensation targets** are out of scope and may surface as `BindingResolutionException` when the container cannot resolve the middleware signature. When your compensation logic lives in a middleware or Action class, wrap it in a classic `handle(): void` method instead, or implement the `CompensableJob` interface.

## Invalid step classes

A class defining **neither** `handle()` nor `__invoke()` raises `InvalidPipelineDefinition::stepClassMissingInvocationMethod()` the first time the pipeline tries to invoke it. Validation is lazy (call time) rather than eager (build time) because `StepDefinition::fromJobClass()` accepts any string today.

```php
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;

try {
    JobPipeline::make([MissingInvocationClass::class])->run();
} catch (StepExecutionFailed $wrapper) {
    // The original InvalidPipelineDefinition is the $wrapper->getPrevious().
    $original = $wrapper->getPrevious();
    assert($original instanceof InvalidPipelineDefinition);
}
```

## Mixed pipelines

You can mix all three shapes freely in the same pipeline.

```php
JobPipeline::make([
    ValidateOrder::class,        // Default handle()
    AuditEveryStep::class,       // Middleware handle($passable, Closure $next)
    SendWelcomeEmail::class,     // Action __invoke(?PipelineContext $context)
])
    ->send(new OrderContext(order: $order))
    ->run();
```

Order is preserved (declaration order of the steps array or `->step()` chaining). Each step fires the usual lifecycle hooks, pipeline level callbacks, and pipeline events identically regardless of its shape.

## Per step configuration

Per step queue, connection, retry, backoff, timeout, and `when()` / `unless()` apply to the **wrapper** (`PipelineStepJob`) not to the step class itself. Middleware shape and Action shape classes therefore inherit per step configuration unchanged.

```php
JobPipeline::make()
    ->step(MiddlewareStep::class)
    ->onQueue('high')
    ->retry(3)
    ->backoff(5)
    ->step(ActionStep::class)
    ->when(fn (?OrderContext $ctx) => $ctx->status === 'pending')
    ->run();
```

## Detection caching

The pipeline memoizes the detected shape per class in a process scoped cache. Queue workers processing many instances of the same class pay the reflection cost only once per process. Tests that create anonymous fixture classes should call `StepInvocationDispatcher::clearCache()` in `beforeEach` to avoid cross test contamination:

```php
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvocationDispatcher;

beforeEach(function (): void {
    StepInvocationDispatcher::clearCache();
});
```
