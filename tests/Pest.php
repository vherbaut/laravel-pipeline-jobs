<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Tests\IntegrationTestCase;
use Vherbaut\LaravelPipelineJobs\Tests\TestCase;

// The opt-in integration harness (sqlite + real `database` queue driver +
// inline `job_batches` migration) lives in tests/Integration/ so it does
// NOT overlap with the default TestCase scope — Pest's uses()->in() only
// allows one parent class per path, so non-overlapping directories are a
// requirement. See tests/IntegrationTestCase.php for the harness.
uses(IntegrationTestCase::class)->in(__DIR__.'/Integration');
uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Unit');
