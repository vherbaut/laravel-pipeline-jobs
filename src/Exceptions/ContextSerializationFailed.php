<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Exceptions;

/**
 * Thrown when a pipeline context cannot be serialized or deserialized.
 */
class ContextSerializationFailed extends PipelineException
{
    /**
     * Create an exception for a non-serializable property on a context class.
     *
     * @param  string  $className  The fully qualified class name of the context.
     * @param  string  $propertyName  The name of the property that failed validation.
     * @param  string  $reason  A human-readable explanation of why the property is not serializable.
     */
    public static function forProperty(string $className, string $propertyName, string $reason): self
    {
        return new self(
            "Property \"{$propertyName}\" on context \"{$className}\" is not serializable: {$reason}",
        );
    }
}
