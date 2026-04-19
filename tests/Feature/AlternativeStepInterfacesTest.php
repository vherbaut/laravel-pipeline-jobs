<?php

declare(strict_types=1);

/*
 * Story 9.4 — Alternative Step Interfaces (Middleware & Actions).
 *
 * Covers FR44 (middleware contract `handle($passable, Closure $next)`) and
 * FR45 (invokable Action contract `__invoke()`). Tests pin sync + queued
 * execution and integration with hooks (Story 6.x), pipeline-level callbacks
 * (Story 6.2), events (Story 9.1), per-step config (Stories 7.1/7.2),
 * conditional execution (Story 4.1), branching (Story 8.3), parallel groups
 * (Story 8.1), nested pipelines (Story 8.2), and admission-control gates
 * (Story 9.3).
 */

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;
use Vherbaut\LaravelPipelineJobs\Execution\PipelineStepJob;
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvocationDispatcher;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;
use Vherbaut\LaravelPipelineJobs\Step;
use Vherbaut\LaravelPipelineJobs\StepDefinition;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ActionEnrichJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ActionFlakyJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ActionTraitEnrichJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\ActionWithDependencyJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\EnrichContextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\InvalidStepClass;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\MiddlewareEnrichJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\MiddlewareFlakyJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\MiddlewareTraitEnrichJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Jobs\MiddlewareWithoutNextJob;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Services\SimpleContextRegistry;

beforeEach(function (): void {
    StepInvocationDispatcher::clearCache();
    MiddlewareFlakyJob::$attempts = 0;
    ActionFlakyJob::$attempts = 0;
    MiddlewareEnrichJob::$invocations = 0;
    ActionEnrichJob::$invocations = 0;
    Cache::flush();
    RateLimiter::clear('alt-step:rate');
});

dataset('dualApi', [
    'array API' => [
        fn (string $class) => new PipelineBuilder([$class]),
    ],
    'fluent API' => [
        fn (string $class) => (new PipelineBuilder)->step($class),
    ],
]);

// -----------------------------------------------------------------------------
// AC #2 / #3 — single-shape execution + dual API parity
// -----------------------------------------------------------------------------

it('runs a single middleware-shape step and mutates context', function (Closure $factory): void {
    $context = new SimpleContext;

    $factory(MiddlewareEnrichJob::class)->send($context)->run();

    expect($context->name)->toBe('middleware-enriched');
})->with('dualApi');

it('runs a single Action-shape step and mutates context', function (Closure $factory): void {
    $context = new SimpleContext;

    $factory(ActionEnrichJob::class)->send($context)->run();

    expect($context->name)->toBe('action-enriched');
})->with('dualApi');

// -----------------------------------------------------------------------------
// AC #2 — middleware $passable / next contract details
// -----------------------------------------------------------------------------

it('forwards the same context instance to middleware as $passable', function (): void {
    $context = new SimpleContext;
    $observed = null;

    $probeMiddleware = new class
    {
        public static mixed $observedPassable = null;

        public static ?Closure $captureNext = null;

        public function handle(mixed $passable, Closure $next): mixed
        {
            self::$observedPassable = $passable;
            self::$captureNext = $next;

            return $next($passable);
        }
    };

    app()->instance($probeMiddleware::class, $probeMiddleware);

    JobPipeline::make([$probeMiddleware::class])->send($context)->run();

    expect($probeMiddleware::$observedPassable)->toBe($context)
        ->and($probeMiddleware::$captureNext)->toBeInstanceOf(Closure::class);
});

it('runs a middleware step that does not call $next without erroring', function (): void {
    $context = new SimpleContext;

    JobPipeline::make([MiddlewareWithoutNextJob::class, EnrichContextJob::class])
        ->send($context)
        ->run();

    expect($context->name)->toBe('enriched');
});

// -----------------------------------------------------------------------------
// AC #3 — Action context binding (named param + DI mix + trait)
// -----------------------------------------------------------------------------

it('binds the live context to an Action via the named "context" parameter', function (): void {
    $context = new SimpleContext;

    JobPipeline::make([ActionEnrichJob::class])->send($context)->run();

    expect($context->name)->toBe('action-enriched');
});

it('runs an Action with a container DI dependency alongside the named context', function (): void {
    $registry = new SimpleContextRegistry;
    app()->instance(SimpleContextRegistry::class, $registry);

    $context = new SimpleContext;
    $context->name = 'pre-recorded';

    JobPipeline::make([ActionWithDependencyJob::class])->send($context)->run();

    expect($registry->all())->toBe(['pre-recorded']);
});

// -----------------------------------------------------------------------------
// AC #12 — InteractsWithPipeline trait works for both new shapes
// -----------------------------------------------------------------------------

it('honors InteractsWithPipeline on a middleware-shape step', function (): void {
    $context = new SimpleContext;

    JobPipeline::make([MiddlewareTraitEnrichJob::class])->send($context)->run();

    expect($context->name)->toBe('middleware-trait-enriched');
});

it('honors InteractsWithPipeline on an Action-shape step', function (): void {
    $context = new SimpleContext;

    JobPipeline::make([ActionTraitEnrichJob::class])->send($context)->run();

    expect($context->name)->toBe('action-trait-enriched');
});

// -----------------------------------------------------------------------------
// AC #2 / #3 / #22 — mixed pipeline executes in declared order, dual API
// -----------------------------------------------------------------------------

it('runs a mixed (handle + middleware + action) pipeline in declared order', function (Closure $factory): void {
    $context = new SimpleContext;

    $factory($context)->run();

    expect($context->name)->toBe('action-enriched');
})->with([
    'array API' => [
        fn (SimpleContext $context) => JobPipeline::make([
            EnrichContextJob::class,
            MiddlewareEnrichJob::class,
            ActionEnrichJob::class,
        ])->send($context),
    ],
    'fluent API' => [
        fn (SimpleContext $context) => (new PipelineBuilder)
            ->step(EnrichContextJob::class)
            ->step(MiddlewareEnrichJob::class)
            ->step(ActionEnrichJob::class)
            ->send($context),
    ],
]);

// -----------------------------------------------------------------------------
// AC #4 — invalid class detection at call time
// -----------------------------------------------------------------------------

it('throws InvalidPipelineDefinition when a step class lacks handle() and __invoke()', function (): void {
    expect(fn () => JobPipeline::make([InvalidStepClass::class])->send(new SimpleContext)->run())
        ->toThrow(StepExecutionFailed::class);
});

it('wraps the underlying InvalidPipelineDefinition with the documented AC #4 message', function (): void {
    try {
        JobPipeline::make([InvalidStepClass::class])->send(new SimpleContext)->run();
        $this->fail('Expected exception not thrown.');
    } catch (StepExecutionFailed $exception) {
        $previous = $exception->getPrevious();
        expect($previous)->toBeInstanceOf(InvalidPipelineDefinition::class)
            ->and($previous->getMessage())->toContain('must define handle() (single-arg or middleware-shape handle($passable, Closure $next)) or __invoke()');
    }
});

// -----------------------------------------------------------------------------
// AC #17 — conditional execution (Step::when / Step::unless)
// -----------------------------------------------------------------------------

it('skips a middleware-shape step under Step::when() when condition is false', function (Closure $factory): void {
    $context = new SimpleContext;

    $factory()->send($context)->run();

    expect($context->name)->toBe('');
})->with([
    'array API' => [
        fn () => new PipelineBuilder([Step::when(fn () => false, MiddlewareEnrichJob::class)]),
    ],
    'fluent API' => [
        fn () => (new PipelineBuilder)->when(fn () => false, MiddlewareEnrichJob::class),
    ],
]);

it('skips an Action-shape step under Step::unless() when condition is true', function (): void {
    $context = new SimpleContext;

    JobPipeline::make([Step::unless(fn () => true, ActionEnrichJob::class)])->send($context)->run();

    expect($context->name)->toBe('');
});

// -----------------------------------------------------------------------------
// AC #18 — conditional branching accepts middleware/action branch values
// -----------------------------------------------------------------------------

it('routes through a branch returning the middleware key', function (): void {
    $context = new SimpleContext;
    $context->name = 'm';

    JobPipeline::make([
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'm' => MiddlewareEnrichJob::class,
            'a' => ActionEnrichJob::class,
        ]),
    ])->send($context)->run();

    expect($context->name)->toBe('middleware-enriched');
});

it('routes through a branch returning the action key', function (): void {
    $context = new SimpleContext;
    $context->name = 'a';

    JobPipeline::make([
        Step::branch(fn (SimpleContext $ctx) => $ctx->name, [
            'm' => MiddlewareEnrichJob::class,
            'a' => ActionEnrichJob::class,
        ]),
    ])->send($context)->run();

    expect($context->name)->toBe('action-enriched');
});

// -----------------------------------------------------------------------------
// AC #19 — parallel groups accept middleware/action sub-steps
// -----------------------------------------------------------------------------

it('runs middleware + action sub-steps inside parallel() (sync)', function (): void {
    $context = new SimpleContext;

    $builder = (new PipelineBuilder)->parallel([
        MiddlewareEnrichJob::class,
        ActionEnrichJob::class,
    ]);

    $builder->send($context)->run();

    expect($context->name)
        ->toBeIn(['middleware-enriched', 'action-enriched']);
});

// -----------------------------------------------------------------------------
// AC #20 — nested pipelines accept middleware/action inner steps
// -----------------------------------------------------------------------------

it('runs middleware + action inner steps inside nest() (sync)', function (): void {
    $context = new SimpleContext;

    JobPipeline::make()
        ->nest(JobPipeline::make([
            MiddlewareEnrichJob::class,
            ActionEnrichJob::class,
        ]))
        ->send($context)
        ->run();

    expect($context->name)->toBe('action-enriched');
});

// -----------------------------------------------------------------------------
// AC #13 — per-step lifecycle hooks fire for middleware + action shapes
// -----------------------------------------------------------------------------

it('fires beforeEach + afterEach hooks for a middleware-shape step', function (): void {
    $events = [];

    JobPipeline::make([MiddlewareEnrichJob::class])
        ->beforeEach(function (StepDefinition $step) use (&$events): void {
            $events[] = ['before', $step->jobClass];
        })
        ->afterEach(function (StepDefinition $step) use (&$events): void {
            $events[] = ['after', $step->jobClass];
        })
        ->send(new SimpleContext)
        ->run();

    expect($events)->toBe([
        ['before', MiddlewareEnrichJob::class],
        ['after', MiddlewareEnrichJob::class],
    ]);
});

it('fires beforeEach + afterEach hooks for an Action-shape step', function (): void {
    $events = [];

    JobPipeline::make([ActionEnrichJob::class])
        ->beforeEach(function (StepDefinition $step) use (&$events): void {
            $events[] = ['before', $step->jobClass];
        })
        ->afterEach(function (StepDefinition $step) use (&$events): void {
            $events[] = ['after', $step->jobClass];
        })
        ->send(new SimpleContext)
        ->run();

    expect($events)->toBe([
        ['before', ActionEnrichJob::class],
        ['after', ActionEnrichJob::class],
    ]);
});

// -----------------------------------------------------------------------------
// AC #14 — pipeline-level onSuccess + onComplete fire for both shapes
// -----------------------------------------------------------------------------

it('fires onSuccess + onComplete callbacks for a middleware-only pipeline', function (): void {
    $events = [];

    JobPipeline::make([MiddlewareEnrichJob::class])
        ->onSuccess(function () use (&$events): void {
            $events[] = 'success';
        })
        ->onComplete(function () use (&$events): void {
            $events[] = 'complete';
        })
        ->send(new SimpleContext)
        ->run();

    expect($events)->toBe(['success', 'complete']);
});

it('fires onSuccess + onComplete callbacks for an action-only pipeline', function (): void {
    $events = [];

    JobPipeline::make([ActionEnrichJob::class])
        ->onSuccess(function () use (&$events): void {
            $events[] = 'success';
        })
        ->onComplete(function () use (&$events): void {
            $events[] = 'complete';
        })
        ->send(new SimpleContext)
        ->run();

    expect($events)->toBe(['success', 'complete']);
});

// -----------------------------------------------------------------------------
// AC #15 — events carry the correct step class for both shapes
// -----------------------------------------------------------------------------

it('dispatches PipelineStepCompleted carrying the middleware step class', function (): void {
    Event::fake([PipelineStepCompleted::class, PipelineCompleted::class]);

    JobPipeline::make([MiddlewareEnrichJob::class])
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event) => $event->stepClass === MiddlewareEnrichJob::class,
    );
});

it('dispatches PipelineStepCompleted carrying the Action step class', function (): void {
    Event::fake([PipelineStepCompleted::class, PipelineCompleted::class]);

    JobPipeline::make([ActionEnrichJob::class])
        ->dispatchEvents()
        ->send(new SimpleContext)
        ->run();

    Event::assertDispatched(
        PipelineStepCompleted::class,
        fn (PipelineStepCompleted $event) => $event->stepClass === ActionEnrichJob::class,
    );
});

// -----------------------------------------------------------------------------
// AC #16 — per-step retry/onQueue/sync apply to wrapper, transparent to shape
// -----------------------------------------------------------------------------

it('honors per-step retry on a middleware-shape step', function (): void {
    $context = new SimpleContext;

    JobPipeline::make()
        ->step(MiddlewareFlakyJob::class)
        ->retry(2)
        ->backoff(0)
        ->send($context)
        ->run();

    expect(MiddlewareFlakyJob::$attempts)->toBe(2)
        ->and($context->name)->toBe('middleware-flaky-succeeded');
});

it('honors per-step retry on an Action-shape step', function (): void {
    $context = new SimpleContext;

    JobPipeline::make()
        ->step(ActionFlakyJob::class)
        ->retry(2)
        ->backoff(0)
        ->send($context)
        ->run();

    expect(ActionFlakyJob::$attempts)->toBe(2)
        ->and($context->name)->toBe('action-flaky-succeeded');
});

it('routes a queued middleware-shape step wrapper to onQueue("high")', function (): void {
    Bus::fake();

    JobPipeline::make()
        ->step(MiddlewareEnrichJob::class)
        ->onQueue('high')
        ->shouldBeQueued()
        ->send(new SimpleContext)
        ->run();

    Bus::assertDispatched(
        PipelineStepJob::class,
        fn (PipelineStepJob $job) => $job->queue === 'high',
    );
});

it('honors ->sync() on a middleware-shape step inside a queued pipeline', function (): void {
    Bus::fake();

    JobPipeline::make()
        ->step(MiddlewareEnrichJob::class)
        ->sync()
        ->shouldBeQueued()
        ->send(new SimpleContext)
        ->run();

    Bus::assertDispatchedSync(PipelineStepJob::class);
});

// -----------------------------------------------------------------------------
// Queued mode (sync queue driver) — Story 9.4 AC #16, #19, #20 + 9.3 composition
// -----------------------------------------------------------------------------

describe('queued mode', function (): void {
    beforeEach(function (): void {
        config()->set('queue.default', 'sync');
    });

    it('runs a queued pipeline with a single middleware-shape step end-to-end', function (): void {
        Bus::fake();

        JobPipeline::make([MiddlewareEnrichJob::class])
            ->shouldBeQueued()
            ->send(new SimpleContext)
            ->run();

        Bus::assertDispatched(PipelineStepJob::class);
    });

    it('runs a queued pipeline with a single Action-shape step end-to-end', function (): void {
        Bus::fake();

        JobPipeline::make([ActionEnrichJob::class])
            ->shouldBeQueued()
            ->send(new SimpleContext)
            ->run();

        Bus::assertDispatched(PipelineStepJob::class);
    });

    it('runs a mixed queued chain (handle + middleware + action) under sync driver', function (): void {
        JobPipeline::make([
            EnrichContextJob::class,
            MiddlewareEnrichJob::class,
            ActionEnrichJob::class,
        ])
            ->shouldBeQueued()
            ->send(new SimpleContext)
            ->run();

        expect(MiddlewareEnrichJob::$invocations)->toBe(1)
            ->and(ActionEnrichJob::$invocations)->toBe(1);
    });

    it('dispatches the PipelineStepJob wrapper for a queued parallel group of mixed shapes', function (): void {
        Bus::fake();

        JobPipeline::make()
            ->parallel([MiddlewareEnrichJob::class, ActionEnrichJob::class])
            ->shouldBeQueued()
            ->send(new SimpleContext)
            ->run();

        Bus::assertDispatched(PipelineStepJob::class);
    });

    it('runs middleware + action inner steps inside nest() under sync driver', function (): void {
        JobPipeline::make()
            ->nest(JobPipeline::make([
                MiddlewareEnrichJob::class,
                ActionEnrichJob::class,
            ]))
            ->shouldBeQueued()
            ->send(new SimpleContext)
            ->run();

        expect(MiddlewareEnrichJob::$invocations)->toBe(1)
            ->and(ActionEnrichJob::$invocations)->toBe(1);
    });

    it('composes Story 9.3 admission gates with middleware-shape steps (regression)', function (): void {
        $context = new SimpleContext;

        JobPipeline::make()
            ->rateLimit('alt-step:rate', 5, 60)
            ->step(MiddlewareEnrichJob::class)
            ->send($context)
            ->run();

        expect($context->name)->toBe('middleware-enriched');
    });
});
