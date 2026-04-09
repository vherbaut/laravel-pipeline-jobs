<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Exceptions;

/**
 * Thrown when a pipeline context cannot be serialized or deserialized.
 */
class ContextSerializationFailed extends PipelineException {}
