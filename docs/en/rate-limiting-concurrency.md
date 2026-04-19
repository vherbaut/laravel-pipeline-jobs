# Rate Limiting and Concurrency Control

A pipeline can carry two orthogonal admission gates evaluated **before any step executes**.

- `rateLimit(key, max, perSeconds)` bounds how many times a pipeline may run within a rolling window.
- `maxConcurrent(key, limit)` bounds how many instances of the pipeline may run **at the same time**.

When either gate rejects the admission, the pipeline throws **before** any step fires, before any hook runs, before any event dispatches. The contract is atomic: a rejected pipeline is a no op on every observable surface.

## Rate limiting

`rateLimit()` integrates with Laravel's `RateLimiter` facade.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    FetchRemoteData::class,
    ProcessData::class,
])
    ->rateLimit('remote-api', max: 60, perSeconds: 60)
    ->send(new DataContext())
    ->run();
```

The pipeline above may run at most 60 times per 60 seconds on the `'remote-api'` key. When the quota is exhausted, `run()` (or `toListener()`) throws `PipelineThrottled` with the retry after delay returned by `RateLimiter::availableIn()`.

```php
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineThrottled;

try {
    $pipeline->run();
} catch (PipelineThrottled $throttled) {
    return response()->json([
        'error'       => 'too-many-pipelines',
        'retry_after' => $throttled->retryAfterSeconds,
    ], 429);
}
```

## Maximum concurrency

`maxConcurrent()` uses a Cache backed atomic counter to limit simultaneous in flight pipelines.

```php
JobPipeline::make([
    ExpensiveReport::class,
])
    ->maxConcurrent('expensive-reports', limit: 4)
    ->send(new ReportContext($definition))
    ->run();
```

Only four instances of the report pipeline may run simultaneously on the same key. The fifth attempt throws `PipelineConcurrencyLimitExceeded`. On admission, the counter is incremented. At terminal exit (success, failure, or compensation completion), the slot is released.

### Cache driver requirement

`maxConcurrent()` relies on `Cache::increment()` being atomic across workers. Drivers that guarantee atomicity:

- Redis.
- Memcached.
- Database.

Drivers that do **not** guarantee atomicity (and must therefore **not** be used with `maxConcurrent()` in production):

- `file`.
- `array`.

The counter is namespaced under `pipeline:concurrent:<key>` with a safety TTL of `max(3600, limit * 60)` seconds so a crashed worker's never released slot eventually reclaims.

## Dynamic keys via Closure

Both methods accept a string key OR a `Closure(?PipelineContext): string` for runtime key resolution.

```php
JobPipeline::make([SendReport::class])
    ->rateLimit(
        fn (?ReportContext $ctx) => 'report:tenant:'.$ctx->tenantId,
        max: 10,
        perSeconds: 60,
    )
    ->maxConcurrent(
        fn (?ReportContext $ctx) => 'report:tenant:'.$ctx->tenantId.':inflight',
        limit: 2,
    )
    ->send(new ReportContext($definition, $tenantId))
    ->run();
```

Closures fire **exactly once per admission attempt**, after `send()` resolves the context. They must return a non empty string. Any other return type throws `InvalidPipelineDefinition` at admission time. Closure throws propagate verbatim to the caller.

## Composition

Both gates can be set on the same pipeline.

```php
JobPipeline::make([SendEmail::class])
    ->rateLimit('email', max: 1_000, perSeconds: 3_600)   // 1,000 emails / hour
    ->maxConcurrent('email', limit: 10)                    // 10 workers max
    ->run();
```

Evaluation order is deterministic: **rate limit runs first**. A quota exhausted attempt throws `PipelineThrottled` without consuming a concurrency slot. Only when the rate limit passes does `maxConcurrent` increment the counter.

## Last write wins

Calling `rateLimit()` or `maxConcurrent()` multiple times on the same builder overrides the previous policy. The builder keeps a single `RateLimitPolicy` and a single `ConcurrencyPolicy`.

## Validation at build time

Both methods validate arguments at build time and throw `InvalidPipelineDefinition` on invalid input.

- Empty string literal key.
- `max < 1` (rate limit).
- `perSeconds < 1` (rate limit).
- `limit < 1` (max concurrent).

Closures are validated at admission time, not build time, because the resolved key is runtime data.

## Zero overhead when unused

When neither method is called, the executor never resolves the `RateLimiter` or `Cache` facades. There is no static probe, no global registry, no per call guard. The gates are strictly opt in.

## Interaction with events and hooks

A throttled or rejected admission runs **before** hooks and events. So:

- `beforeEach`, `afterEach`, `onStepFailed` hooks do not fire.
- `onSuccess`, `onFailure`, `onComplete` callbacks do not fire.
- `PipelineStepCompleted`, `PipelineStepFailed`, `PipelineCompleted` events do not fire.

The caller observes the failure exclusively through the thrown `PipelineThrottled` or `PipelineConcurrencyLimitExceeded`. Instrument admission rejection at the call site if you need to record it.

## Testing

`Pipeline::fake()` in default mode treats both gates as **inert**. The pipeline admits unconditionally, no Cache or RateLimiter calls are made. Useful to write assertions on the recorded definition without requiring a real Redis in the test runtime.

`Pipeline::fake()->recording()` honors both gates exactly like production. Useful to exercise the quota exhaustion or concurrency limit in integration tests.

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineThrottled;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Cache::flush();
RateLimiter::clear('k');
Pipeline::fake()->recording();

Pipeline::make([StepA::class])->rateLimit('k', max: 1, perSeconds: 60)->run();

expect(static fn () => Pipeline::make([StepA::class])->rateLimit('k', max: 1, perSeconds: 60)->run())
    ->toThrow(PipelineThrottled::class);
```

## Exceptions at a glance

| Exception | Thrown when |
|-----------|-------------|
| `PipelineThrottled` | `rateLimit()` quota exhausted at admission. Carries `retryAfterSeconds`. |
| `PipelineConcurrencyLimitExceeded` | `maxConcurrent()` limit reached at admission. |
| `InvalidPipelineDefinition` | Build time: invalid arguments. Admission time: closure returned a non string. |
