<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Context;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use ReflectionClass;
use ReflectionProperty;
use Serializable;
use Vherbaut\LaravelPipelineJobs\Exceptions\ContextSerializationFailed;

/**
 * Base class for pipeline context objects.
 *
 * Extend this class with typed properties to pass structured,
 * type-safe data between pipeline steps. Properties are serialized
 * into each job's payload via PHP's native serialization.
 * Eloquent models are handled transparently via SerializesModels.
 */
class PipelineContext
{
    use SerializesModels;

    /**
     * Validate that all properties on this context are serializable.
     *
     * Checks all public and protected properties on the concrete subclass.
     * Scalars, arrays, Eloquent models, and objects implementing Serializable
     * or __serialize are allowed. Closures, resources, and anonymous classes
     * are rejected.
     *
     * @throws ContextSerializationFailed When a property holds a non-serializable value.
     */
    public function validateSerializable(): void
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);

        foreach ($properties as $property) {
            if (! $property->isInitialized($this)) {
                continue;
            }

            $value = $property->getValue($this);

            if ($value === null) {
                continue;
            }

            if (is_scalar($value) || is_array($value)) {
                continue;
            }

            if ($value instanceof Model) {
                continue;
            }

            if ($value instanceof Closure) {
                throw ContextSerializationFailed::forProperty(
                    static::class,
                    $property->getName(),
                    'Closures are not serializable',
                );
            }

            if (is_resource($value)) {
                throw ContextSerializationFailed::forProperty(
                    static::class,
                    $property->getName(),
                    'Resources are not serializable',
                );
            }

            if (is_object($value)) {
                $valueReflection = new ReflectionClass($value);

                if ($valueReflection->isAnonymous()) {
                    throw ContextSerializationFailed::forProperty(
                        static::class,
                        $property->getName(),
                        'Anonymous classes are not serializable',
                    );
                }

                if ($value instanceof Serializable || method_exists($value, '__serialize')) {
                    continue;
                }

                // Standard objects are serializable via PHP's native mechanism
                continue;
            }
        }
    }
}
