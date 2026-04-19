<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvocationDispatcher;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvocationStrategy;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

beforeEach(function (): void {
    StepInvocationDispatcher::clearCache();
});

it('detects Default for a class with parameterless handle()', function (): void {
    $job = new class
    {
        public function handle(): void {}
    };

    expect(StepInvocationDispatcher::detect($job))->toBe(StepInvocationStrategy::Default);
});

it('detects Default for a class with handle() that takes a single non-Closure DI parameter', function (): void {
    $job = new class
    {
        public function handle(SimpleContext $context): void {}
    };

    expect(StepInvocationDispatcher::detect($job))->toBe(StepInvocationStrategy::Default);
});

it('detects Middleware for a class with handle($passable, Closure $next)', function (): void {
    $job = new class
    {
        public function handle(mixed $passable, Closure $next): mixed
        {
            return $next($passable);
        }
    };

    expect(StepInvocationDispatcher::detect($job))->toBe(StepInvocationStrategy::Middleware);
});

it('detects Middleware when the first param is typed and the second is Closure', function (): void {
    $job = new class
    {
        public function handle(SimpleContext $passable, Closure $next): mixed
        {
            return $next($passable);
        }
    };

    expect(StepInvocationDispatcher::detect($job))->toBe(StepInvocationStrategy::Middleware);
});

it('detects Default when the second param is a union type containing Closure', function (): void {
    $job = new class
    {
        public function handle(mixed $passable, Closure|string $next): void {}
    };

    expect(StepInvocationDispatcher::detect($job))->toBe(StepInvocationStrategy::Default);
});

it('detects Action for a class with __invoke() and no handle()', function (): void {
    $job = new class
    {
        public function __invoke(): void {}
    };

    expect(StepInvocationDispatcher::detect($job))->toBe(StepInvocationStrategy::Action);
});

it('detects Action for a class with __invoke(?PipelineContext $context) and no handle()', function (): void {
    $job = new class
    {
        public function __invoke(?PipelineContext $context): void {}
    };

    expect(StepInvocationDispatcher::detect($job))->toBe(StepInvocationStrategy::Action);
});

it('prefers Default over Action when a class has both handle() and __invoke()', function (): void {
    $job = new class
    {
        public function handle(): void {}

        public function __invoke(): void {}
    };

    expect(StepInvocationDispatcher::detect($job))->toBe(StepInvocationStrategy::Default);
});

it('throws InvalidPipelineDefinition when a class has neither handle() nor __invoke()', function (): void {
    $job = new class
    {
        public function report(): void {}
    };

    expect(fn () => StepInvocationDispatcher::detect($job))
        ->toThrow(
            InvalidPipelineDefinition::class,
            'must define handle() (single-arg or middleware-shape handle($passable, Closure $next)) or __invoke()',
        );
});

it('memoizes detection results in a per-class cache', function (): void {
    $jobA = new class
    {
        public function handle(): void {}
    };

    StepInvocationDispatcher::detect($jobA);

    $reflection = new ReflectionClass(StepInvocationDispatcher::class);
    $cacheProp = $reflection->getProperty('cache');

    expect($cacheProp->getValue())->toHaveCount(1);

    StepInvocationDispatcher::detect($jobA);
    StepInvocationDispatcher::detect($jobA);

    expect($cacheProp->getValue())->toHaveCount(1);
});

it('clearCache empties the static cache', function (): void {
    $job = new class
    {
        public function handle(): void {}
    };

    StepInvocationDispatcher::detect($job);

    $reflection = new ReflectionClass(StepInvocationDispatcher::class);
    $cacheProp = $reflection->getProperty('cache');

    expect($cacheProp->getValue())->not->toBe([]);

    StepInvocationDispatcher::clearCache();

    expect($cacheProp->getValue())->toBe([]);
});

it('call() invokes handle() for a Default-strategy fixture', function (): void {
    $job = new class
    {
        public bool $called = false;

        public function handle(): void
        {
            $this->called = true;
        }
    };

    StepInvocationDispatcher::call($job, null);

    expect($job->called)->toBeTrue();
});

it('call() routes context to middleware via $passable', function (): void {
    $job = new class
    {
        public mixed $observedPassable = 'unset';

        public function handle(mixed $passable, Closure $next): mixed
        {
            $this->observedPassable = $passable;

            return $next($passable);
        }
    };

    $context = new SimpleContext;
    $context->name = 'observed';

    StepInvocationDispatcher::call($job, $context);

    expect($job->observedPassable)->toBe($context);
});

it('call() binds context to __invoke()s "context" parameter for Action strategy', function (): void {
    $job = new class
    {
        public ?PipelineContext $observedContext = null;

        public function __invoke(?PipelineContext $context): void
        {
            $this->observedContext = $context;
        }
    };

    $context = new SimpleContext;
    $context->name = 'observed';

    StepInvocationDispatcher::call($job, $context);

    expect($job->observedContext)->toBe($context);
});

it('call() accepts a null context for middleware fixtures', function (): void {
    $job = new class
    {
        public mixed $observedPassable = 'unset';

        public function handle(mixed $passable, Closure $next): mixed
        {
            $this->observedPassable = $passable;

            return $next($passable);
        }
    };

    StepInvocationDispatcher::call($job, null);

    expect($job->observedPassable)->toBeNull();
});

it('call() accepts a null context for action fixtures', function (): void {
    $job = new class
    {
        public bool $invoked = false;

        public ?PipelineContext $observedContext = null;

        public function __invoke(?PipelineContext $context): void
        {
            $this->invoked = true;
            $this->observedContext = $context;
        }
    };

    StepInvocationDispatcher::call($job, null);

    expect($job->invoked)->toBeTrue()
        ->and($job->observedContext)->toBeNull();
});

it('call() propagates InvalidPipelineDefinition for invalid classes', function (): void {
    $job = new class
    {
        public function report(): void {}
    };

    expect(fn () => StepInvocationDispatcher::call($job, null))
        ->toThrow(InvalidPipelineDefinition::class);
});

it('call() propagates exceptions thrown from the underlying handle()', function (): void {
    $job = new class
    {
        public function handle(): void
        {
            throw new RuntimeException('boom');
        }
    };

    expect(fn () => StepInvocationDispatcher::call($job, null))
        ->toThrow(RuntimeException::class, 'boom');
});

it('call() propagates exceptions thrown from the underlying __invoke()', function (): void {
    $job = new class
    {
        public function __invoke(): void
        {
            throw new RuntimeException('action-boom');
        }
    };

    expect(fn () => StepInvocationDispatcher::call($job, null))
        ->toThrow(RuntimeException::class, 'action-boom');
});
