<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Execution\Shared;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;
use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;

/**
 * Shared compensation helpers: reflection-based compensate() dispatch plus failure reporting.
 *
 * Consolidates the three previously duplicated compensation bridges
 * (SyncExecutor::runCompensationChain, CompensationStepJob::handle,
 * RecordingExecutor::runCompensation) behind a single static entry point
 * for compensate() invocation, and exposes two reporting entry points:
 * one for the in-line sync / recording catch blocks and one for the
 * queued `failed()` lifecycle hook (different log message, originalException
 * always null because Throwables do not survive queue serialization per NFR19).
 *
 * @internal
 */
final class CompensationInvoker
{
    /**
     * Invoke a CompensableJob's compensate() method, passing the FailureContext when the implementation accepts it.
     *
     * Reflection-based dispatch that only passes the extra FailureContext
     * argument when the implementation's second parameter type can accept it.
     * Rejects signatures that require more than two parameters so subscribers
     * cannot silently request data the executor does not provide.
     *
     * @param CompensableJob $job The compensation job instance resolved from the container.
     * @param PipelineContext|null $context The pipeline context present at the failure point.
     * @param FailureContext|null $failure The failure-context snapshot, or null when no failure was recorded on the manifest.
     * @return void
     *
     * @throws LogicException When the compensate() signature declares more than two required parameters.
     */
    public static function invokeCompensate(CompensableJob $job, ?PipelineContext $context, ?FailureContext $failure): void
    {
        $method = new ReflectionMethod($job, 'compensate');

        if ($method->getNumberOfRequiredParameters() > 2) {
            throw new LogicException(sprintf(
                'Compensation class [%s] declares compensate() with more than two required parameters; the executor only provides $context and $failure.',
                $job::class,
            ));
        }

        $args = self::compensateAcceptsFailureContext($method) ? [$context, $failure] : [$context];
        $method->invoke($job, ...$args);
    }

    /**
     * Emit the NFR6 observability pair for an in-line compensation failure (sync / recording path).
     *
     * Writes a structured `Log::error('Pipeline compensation failed', [...])`
     * line and dispatches a `CompensationFailed` event carrying the pipeline
     * identifier, the compensation class, the failing step class, and both
     * exceptions. Invoked per-iteration from sync/recording best-effort
     * compensation chains; does not abort the surrounding chain.
     *
     * @param PipelineManifest $manifest The manifest carrying pipelineId and failedStepClass.
     * @param string $compensationClass Fully qualified class name of the compensation job that threw.
     * @param Throwable|null $originalException Throwable raised by the failing step, or null when no failure was recorded.
     * @param Throwable $compensationException Throwable raised by the compensation job itself.
     * @return void
     */
    public static function reportCompensationFailure(
        PipelineManifest $manifest,
        string $compensationClass,
        ?Throwable $originalException,
        Throwable $compensationException,
    ): void {
        Log::error('Pipeline compensation failed', [
            'pipelineId' => $manifest->pipelineId,
            'compensationClass' => $compensationClass,
            'failedStepClass' => $manifest->failedStepClass,
            'compensationException' => $compensationException->getMessage(),
        ]);

        Event::dispatch(new CompensationFailedEvent(
            pipelineId: $manifest->pipelineId,
            compensationClass: $compensationClass,
            failedStepClass: $manifest->failedStepClass,
            originalException: $originalException,
            compensationException: $compensationException,
        ));
    }

    /**
     * Emit the NFR6 observability pair for a queued compensation wrapper that exhausted its retries.
     *
     * Distinct from `reportCompensationFailure()` because the queue lifecycle
     * hook (`CompensationStepJob::failed()`) emits a different log message
     * ("Pipeline compensation failed after retries") and always records
     * `originalException: null` since Throwables do not survive queue
     * serialization (NFR19). Operators should correlate via pipelineId with
     * logs emitted by the earlier failed step.
     *
     * @param PipelineManifest $manifest The manifest carrying pipelineId and failedStepClass.
     * @param string $compensationClass Fully qualified class name of the compensation job that threw.
     * @param Throwable $compensationException Throwable captured by Laravel's queue worker.
     * @return void
     */
    public static function reportQueuedCompensationFailure(
        PipelineManifest $manifest,
        string $compensationClass,
        Throwable $compensationException,
    ): void {
        Log::error('Pipeline compensation failed after retries', [
            'pipelineId' => $manifest->pipelineId,
            'compensationClass' => $compensationClass,
            'failedStepClass' => $manifest->failedStepClass,
            'compensationException' => $compensationException->getMessage(),
        ]);

        Event::dispatch(new CompensationFailedEvent(
            pipelineId: $manifest->pipelineId,
            compensationClass: $compensationClass,
            failedStepClass: $manifest->failedStepClass,
            originalException: null,
            compensationException: $compensationException,
        ));
    }

    /**
     * Decide whether a compensate() reflection method accepts a FailureContext as its second argument.
     *
     * Returns false for single-parameter signatures and for two-parameter
     * signatures whose second parameter type cannot be satisfied by a
     * FailureContext instance. Untyped, mixed, object, FailureContext, or
     * compatible supertype parameters all return true.
     */
    private static function compensateAcceptsFailureContext(ReflectionMethod $method): bool
    {
        if ($method->getNumberOfParameters() < 2) {
            return false;
        }

        $type = $method->getParameters()[1]->getType();

        if ($type === null) {
            return true;
        }

        return self::typeAcceptsFailureContext($type);
    }

    /**
     * Recursive type-compatibility probe for compensateAcceptsFailureContext().
     *
     * ReflectionIntersectionType always returns false because FailureContext
     * is final and implements no interfaces, so no intersection of types
     * can be satisfied by it.
     */
    private static function typeAcceptsFailureContext(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return $type->getName() === 'mixed' || $type->getName() === 'object';
            }

            $name = $type->getName();

            return $name === FailureContext::class || is_subclass_of(FailureContext::class, $name);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if (self::typeAcceptsFailureContext($inner)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
