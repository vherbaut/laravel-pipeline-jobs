<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Exceptions;

/**
 * Thrown when a compensation (rollback) operation fails during saga execution.
 */
class CompensationFailed extends PipelineException {}
