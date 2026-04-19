<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvocationDispatcher;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvoker;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

beforeEach(function (): void {
    StepInvocationDispatcher::clearCache();
});

it('forwards the context argument to the dispatcher for middleware-shape steps', function (): void {
    $job = new class
    {
        public mixed $observed = 'unset';

        public function handle(mixed $passable, Closure $next): mixed
        {
            $this->observed = $passable;

            return $next($passable);
        }
    };

    $context = new SimpleContext;
    $context->name = 'forwarded';

    StepInvoker::invokeWithRetry(
        $job,
        ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
        $context,
    );

    expect($job->observed)->toBe($context);
});

it('routes default-strategy steps with a null context without erroring', function (): void {
    $job = new class
    {
        public bool $called = false;

        public function handle(): void
        {
            $this->called = true;
        }
    };

    StepInvoker::invokeWithRetry(
        $job,
        ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => null, 'backoff' => null, 'timeout' => null],
        null,
    );

    expect($job->called)->toBeTrue();
});

it('runs the dispatcher on each retry attempt', function (): void {
    $job = new class
    {
        public int $attempts = 0;

        public function handle(): void
        {
            $this->attempts++;

            if ($this->attempts < 3) {
                throw new RuntimeException('flaky');
            }
        }
    };

    StepInvoker::invokeWithRetry(
        $job,
        ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => 2, 'backoff' => 0, 'timeout' => null],
        null,
    );

    expect($job->attempts)->toBe(3);
});

it('routes context to middleware-shape steps across retry attempts', function (): void {
    $job = new class
    {
        public int $attempts = 0;

        public mixed $observed = 'unset';

        public function handle(mixed $passable, Closure $next): mixed
        {
            $this->attempts++;
            $this->observed = $passable;

            if ($this->attempts < 2) {
                throw new RuntimeException('flaky-middleware');
            }

            return $next($passable);
        }
    };

    $context = new SimpleContext;
    $context->name = 'across-retries';

    StepInvoker::invokeWithRetry(
        $job,
        ['queue' => null, 'connection' => null, 'sync' => false, 'retry' => 2, 'backoff' => 0, 'timeout' => null],
        $context,
    );

    expect($job->attempts)->toBe(2)
        ->and($job->observed)->toBe($context);
});
