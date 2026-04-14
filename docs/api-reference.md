# API Reference

Complete catalog of public symbols exposed by the package.

## Table of Contents

- [JobPipeline](#jobpipeline)
- [PipelineBuilder](#pipelinebuilder)
- [InteractsWithPipeline trait](#interactswithpipeline-trait)
- [CompensableJob contract](#compensablejob-contract)
- [FailureContext](#failurecontext)
- [FailStrategy enum](#failstrategy-enum)
- [PipelineContext](#pipelinecontext)
- [PipelineManifest](#pipelinemanifest)
- [Pipeline Facade](#pipeline-facade)
- [Exceptions](#exceptions)
- [Events](#events)

## `JobPipeline`

| Method | Returns | Description |
|--------|---------|-------------|
| `make(array $jobs = [])` | `PipelineBuilder` | Create a new pipeline builder, optionally with an array of job classes. |
| `listen(string $event, array $jobs, ?Closure $send)` | `void` | Register a pipeline as an event listener in a single call. |

## `PipelineBuilder`

| Method | Returns | Description |
|--------|---------|-------------|
| `step(string $jobClass)` | `static` | Add a step to the pipeline. |
| `when(Closure $condition, string $jobClass)` | `static` | Append a step that runs only when the condition (evaluated against the live context) returns truthy. Condition must be serializable in queued mode. |
| `unless(Closure $condition, string $jobClass)` | `static` | Append a step that runs only when the condition (evaluated against the live context) returns falsy. Condition must be serializable in queued mode. |
| `compensateWith(string $jobClass)` | `static` | Assign a compensation job to the last added step. |
| `onFailure(FailStrategy\|Closure $strategyOrCallback)` | `static` | Union overload. Pass a `FailStrategy` enum to set the saga strategy (`StopImmediately` default, `StopAndCompensate`, `SkipAndContinue`). Pass a `Closure(?PipelineContext, \Throwable): void` to register a pipeline-level failure callback. Storage slots are orthogonal. Calling both registers both. |
| `beforeEach(Closure $hook)` | `static` | Register an append-semantic per-step hook invoked before each non-skipped step. Signature: `Closure(StepDefinition, ?PipelineContext): void`. See [lifecycle-hooks.md](lifecycle-hooks.md). |
| `afterEach(Closure $hook)` | `static` | Register an append-semantic per-step hook invoked after each successful step. Signature: `Closure(StepDefinition, ?PipelineContext): void`. |
| `onStepFailed(Closure $hook)` | `static` | Register an append-semantic per-step hook invoked when a step or hook throws. Signature: `Closure(StepDefinition, ?PipelineContext, \Throwable): void`. Fires before `FailStrategy` branching applies. |
| `onSuccess(Closure $callback)` | `static` | Register a last-write-wins pipeline-level callback fired once on terminal success. Signature: `Closure(?PipelineContext): void`. Fires under `SkipAndContinue` even when intermediate steps failed. |
| `onComplete(Closure $callback)` | `static` | Register a last-write-wins pipeline-level callback fired after `onSuccess` or `onFailure` on both terminal branches. Signature: `Closure(?PipelineContext): void`. |
| `send(PipelineContext\|Closure $context)` | `static` | Set the context (instance or closure for deferred resolution). |
| `shouldBeQueued()` | `static` | Mark the pipeline for asynchronous queue execution. |
| `return(Closure $callback)` | `static` | Register a closure that transforms the final context into the value returned by `run()`. Synchronous only. Ignored in queued mode. |
| `build()` | `PipelineDefinition` | Build an immutable pipeline definition from the current builder state. |
| `run()` | `mixed` | Build and execute the pipeline. Returns the `->return()` closure result when registered, otherwise the final `PipelineContext` (or `null`). Always `null` in queued mode. |
| `toListener()` | `Closure` | Convert the pipeline to an event listener closure. |
| `getContext()` | `PipelineContext\|Closure\|null` | Retrieve the currently configured context. |

## `InteractsWithPipeline` trait

| Method | Returns | Description |
|--------|---------|-------------|
| `pipelineContext()` | `?PipelineContext` | The live `PipelineContext` when the job runs inside a pipeline with a context, `null` otherwise. |
| `hasPipelineContext()` | `bool` | `true` when a non null context is currently available, `false` for standalone dispatch or pipelines without `->send(...)`. |
| `failureContext()` | `?FailureContext` | Snapshot of the latest failure recorded on the manifest, or `null` when no failure was recorded or the job runs outside a pipeline. Parallel accessor to `pipelineContext()`. |

## `CompensableJob` contract

Optional interface that compensation jobs may implement instead of using the `InteractsWithPipeline` trait pattern.

| Method | Returns | Description |
|--------|---------|-------------|
| `compensate(PipelineContext $context, ?FailureContext $failure = null)` | `void` | Rollback hook invoked by the executor. The second argument is only delivered when the implementation widens the signature to two parameters (executor detects via reflection). |

## `FailureContext`

Readonly value object built from the manifest at invocation time.

| Property | Type | Description |
|----------|------|-------------|
| `failedStepClass` | `string` | Fully qualified class name of the failing step. |
| `failedStepIndex` | `int` | Zero based index of the failing step. |
| `exception` | `?\Throwable` | Original throwable (non null in sync, always null in queued mode per NFR19). |

| Method | Returns | Description |
|--------|---------|-------------|
| `FailureContext::fromManifest(PipelineManifest $manifest)` | `?self` | Build a snapshot from the manifest, or `null` when no failure was recorded (`failedStepClass === null`). |

## `FailStrategy` enum

| Case | Meaning |
|------|---------|
| `StopImmediately` | Default. Rethrow as `StepExecutionFailed`, no compensation. |
| `StopAndCompensate` | Run compensation chain in reverse order, then rethrow as `StepExecutionFailed`. |
| `SkipAndContinue` | Log warning, skip the failing step, continue. No compensation. No throw. |

## `PipelineContext`

| Method | Returns | Description |
|--------|---------|-------------|
| `validateSerializable()` | `void` | Validate that all properties can be serialized for queue dispatch. |

## `PipelineManifest`

| Property | Type | Description |
|----------|------|-------------|
| `pipelineId` | `string` | Unique identifier (UUID) for this pipeline execution. |
| `stepClasses` | `array<int, string>` | Ordered list of step class names. |
| `compensationMapping` | `array<string, string>` | Map of step class to compensation class. |
| `currentStepIndex` | `int` | Index of the currently executing step. |
| `completedSteps` | `array<int, string>` | Steps that have completed successfully. |
| `context` | `?PipelineContext` | The shared context object. |
| `failStrategy` | `FailStrategy` | Pipeline level failure strategy set via `onFailure()`. |
| `failedStepClass` | `?string` | Class name of the most recent failing step, or `null` when no failure has been recorded. |
| `failedStepIndex` | `?int` | Zero based index of the most recent failing step, or `null`. |
| `failureException` | `?\Throwable` | The live throwable from the most recent failure (null across the queue serialization boundary per NFR19). |

## `Pipeline` Facade

The `Pipeline` facade proxies to `JobPipeline` and adds the `fake()` method for testing.

| Method | Returns | Description |
|--------|---------|-------------|
| `fake()` | `PipelineFake` | Replace the pipeline system with a test double. |

## Exceptions

| Exception | When |
|-----------|------|
| `InvalidPipelineDefinition` | Pipeline has no steps, or `compensateWith()` called before any step. |
| `StepExecutionFailed` | A step threw an exception during synchronous execution. Wraps the original exception. When a pipeline-level callback (`onFailure(Closure)` or `onComplete`) throws on the failure path, `StepExecutionFailed::forCallbackFailure()` produces a variant that preserves the original step exception on `$originalStepException`. |
| `ContextSerializationFailed` | Context contains non serializable properties (closures, resources, anonymous classes). |
| `CompensationFailed` | Base exception class for rollback failures. Available for user code that wants to throw a typed exception from a compensation job. |

## Events

| Event | When |
|-------|------|
| `Vherbaut\LaravelPipelineJobs\Events\CompensationFailed` | Dispatched unconditionally when a compensation job throws (sync best effort catch, queued `failed()` hook). Carries `pipelineId`, `compensationClass`, `failedStepClass`, `originalException` (null in queued), `compensationException`. |
