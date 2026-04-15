<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\StepDefinition;

it('can be created with only a job class name', function () {
    $step = new StepDefinition(jobClass: 'App\\Jobs\\ProcessOrder');

    expect($step->jobClass)->toBe('App\\Jobs\\ProcessOrder')
        ->and($step->compensationJobClass)->toBeNull()
        ->and($step->condition)->toBeNull()
        ->and($step->conditionNegated)->toBeFalse()
        ->and($step->queue)->toBeNull()
        ->and($step->connection)->toBeNull()
        ->and($step->retry)->toBeNull()
        ->and($step->backoff)->toBeNull()
        ->and($step->timeout)->toBeNull()
        ->and($step->sync)->toBeFalse();
});

it('can be created with full configuration', function () {
    $condition = fn () => true;

    $step = new StepDefinition(
        jobClass: 'App\\Jobs\\ProcessOrder',
        compensationJobClass: 'App\\Jobs\\ReverseOrder',
        condition: $condition,
        conditionNegated: true,
        queue: 'high',
        connection: 'redis',
        retry: 3,
        backoff: 10,
        timeout: 120,
        sync: true,
    );

    expect($step->jobClass)->toBe('App\\Jobs\\ProcessOrder')
        ->and($step->compensationJobClass)->toBe('App\\Jobs\\ReverseOrder')
        ->and($step->condition)->toBe($condition)
        ->and($step->conditionNegated)->toBeTrue()
        ->and($step->queue)->toBe('high')
        ->and($step->connection)->toBe('redis')
        ->and($step->retry)->toBe(3)
        ->and($step->backoff)->toBe(10)
        ->and($step->timeout)->toBe(120)
        ->and($step->sync)->toBeTrue();
});

it('exposes all properties via readonly access', function () {
    $step = new StepDefinition(jobClass: 'App\\Jobs\\SendEmail');

    $reflection = new ReflectionClass($step);

    foreach ($reflection->getProperties() as $property) {
        expect($property->isReadOnly())->toBeTrue(
            "Property \"{$property->getName()}\" should be readonly"
        );
    }
});

it('defaults optional properties to null or false', function () {
    $step = new StepDefinition(jobClass: 'App\\Jobs\\Notify');

    expect($step->compensationJobClass)->toBeNull()
        ->and($step->condition)->toBeNull()
        ->and($step->conditionNegated)->toBeFalse()
        ->and($step->queue)->toBeNull()
        ->and($step->connection)->toBeNull()
        ->and($step->retry)->toBeNull()
        ->and($step->backoff)->toBeNull()
        ->and($step->timeout)->toBeNull()
        ->and($step->sync)->toBeFalse();
});

it('can be created via fromJobClass factory method', function () {
    $step = StepDefinition::fromJobClass('App\\Jobs\\ProcessPayment');

    expect($step)->toBeInstanceOf(StepDefinition::class)
        ->and($step->jobClass)->toBe('App\\Jobs\\ProcessPayment')
        ->and($step->compensationJobClass)->toBeNull()
        ->and($step->condition)->toBeNull()
        ->and($step->sync)->toBeFalse();
});

/**
 * Build a fully-populated StepDefinition fixture for "all-fields-preserved"
 * assertions in the fluent-method tests.
 */
function fullyConfiguredStepDefinition(): StepDefinition
{
    return new StepDefinition(
        jobClass: 'App\\Jobs\\ProcessOrder',
        compensationJobClass: 'App\\Jobs\\ReverseOrder',
        condition: fn () => true,
        conditionNegated: true,
        queue: 'initial-queue',
        connection: 'initial-connection',
        retry: 3,
        backoff: 10,
        timeout: 120,
        sync: false,
    );
}

it('onQueue returns a new instance with queue updated and every other field preserved', function () {
    $original = fullyConfiguredStepDefinition();

    $updated = $original->onQueue('heavy');

    expect($updated)->not->toBe($original)
        ->and($updated->queue)->toBe('heavy')
        ->and($updated->jobClass)->toBe($original->jobClass)
        ->and($updated->compensationJobClass)->toBe($original->compensationJobClass)
        ->and($updated->condition)->toBe($original->condition)
        ->and($updated->conditionNegated)->toBe($original->conditionNegated)
        ->and($updated->connection)->toBe($original->connection)
        ->and($updated->retry)->toBe($original->retry)
        ->and($updated->backoff)->toBe($original->backoff)
        ->and($updated->timeout)->toBe($original->timeout)
        ->and($updated->sync)->toBe($original->sync)
        ->and($original->queue)->toBe('initial-queue');
});

it('onConnection returns a new instance with connection updated and every other field preserved', function () {
    $original = fullyConfiguredStepDefinition();

    $updated = $original->onConnection('redis');

    expect($updated)->not->toBe($original)
        ->and($updated->connection)->toBe('redis')
        ->and($updated->jobClass)->toBe($original->jobClass)
        ->and($updated->compensationJobClass)->toBe($original->compensationJobClass)
        ->and($updated->condition)->toBe($original->condition)
        ->and($updated->conditionNegated)->toBe($original->conditionNegated)
        ->and($updated->queue)->toBe($original->queue)
        ->and($updated->retry)->toBe($original->retry)
        ->and($updated->backoff)->toBe($original->backoff)
        ->and($updated->timeout)->toBe($original->timeout)
        ->and($updated->sync)->toBe($original->sync)
        ->and($original->connection)->toBe('initial-connection');
});

it('sync returns a new instance with sync set to true and clears queue and connection', function () {
    $original = fullyConfiguredStepDefinition();

    $updated = $original->sync();

    expect($updated)->not->toBe($original)
        ->and($updated->sync)->toBeTrue()
        ->and($updated->queue)->toBeNull()
        ->and($updated->connection)->toBeNull()
        ->and($updated->jobClass)->toBe($original->jobClass)
        ->and($updated->compensationJobClass)->toBe($original->compensationJobClass)
        ->and($updated->condition)->toBe($original->condition)
        ->and($updated->conditionNegated)->toBe($original->conditionNegated)
        ->and($updated->retry)->toBe($original->retry)
        ->and($updated->backoff)->toBe($original->backoff)
        ->and($updated->timeout)->toBe($original->timeout)
        ->and($original->sync)->toBeFalse();
});

it('chaining sync() after onQueue/onConnection clears them because dispatch_sync overrides both', function () {
    $step = StepDefinition::fromJobClass('App\\Jobs\\Process')
        ->onQueue('heavy')
        ->onConnection('redis')
        ->sync();

    expect($step->jobClass)->toBe('App\\Jobs\\Process')
        ->and($step->queue)->toBeNull()
        ->and($step->connection)->toBeNull()
        ->and($step->sync)->toBeTrue();
});

it('withCompensation returns a new instance with compensation set and every other field preserved', function () {
    $original = fullyConfiguredStepDefinition();

    $updated = $original->withCompensation('App\\Jobs\\Compensate');

    expect($updated)->not->toBe($original)
        ->and($updated->compensationJobClass)->toBe('App\\Jobs\\Compensate')
        ->and($updated->jobClass)->toBe($original->jobClass)
        ->and($updated->condition)->toBe($original->condition)
        ->and($updated->conditionNegated)->toBe($original->conditionNegated)
        ->and($updated->queue)->toBe($original->queue)
        ->and($updated->connection)->toBe($original->connection)
        ->and($updated->retry)->toBe($original->retry)
        ->and($updated->backoff)->toBe($original->backoff)
        ->and($updated->timeout)->toBe($original->timeout)
        ->and($updated->sync)->toBe($original->sync);
});

it('retry returns a new instance with retry updated and every other field preserved', function () {
    $original = fullyConfiguredStepDefinition();

    $updated = $original->retry(7);

    expect($updated)->not->toBe($original)
        ->and($updated->retry)->toBe(7)
        ->and($updated->jobClass)->toBe($original->jobClass)
        ->and($updated->compensationJobClass)->toBe($original->compensationJobClass)
        ->and($updated->condition)->toBe($original->condition)
        ->and($updated->conditionNegated)->toBe($original->conditionNegated)
        ->and($updated->queue)->toBe($original->queue)
        ->and($updated->connection)->toBe($original->connection)
        ->and($updated->backoff)->toBe($original->backoff)
        ->and($updated->timeout)->toBe($original->timeout)
        ->and($updated->sync)->toBe($original->sync)
        ->and($original->retry)->toBe(3);
});

it('backoff returns a new instance with backoff updated and every other field preserved', function () {
    $original = fullyConfiguredStepDefinition();

    $updated = $original->backoff(42);

    expect($updated)->not->toBe($original)
        ->and($updated->backoff)->toBe(42)
        ->and($updated->jobClass)->toBe($original->jobClass)
        ->and($updated->compensationJobClass)->toBe($original->compensationJobClass)
        ->and($updated->condition)->toBe($original->condition)
        ->and($updated->conditionNegated)->toBe($original->conditionNegated)
        ->and($updated->queue)->toBe($original->queue)
        ->and($updated->connection)->toBe($original->connection)
        ->and($updated->retry)->toBe($original->retry)
        ->and($updated->timeout)->toBe($original->timeout)
        ->and($updated->sync)->toBe($original->sync)
        ->and($original->backoff)->toBe(10);
});

it('timeout returns a new instance with timeout updated and every other field preserved', function () {
    $original = fullyConfiguredStepDefinition();

    $updated = $original->timeout(300);

    expect($updated)->not->toBe($original)
        ->and($updated->timeout)->toBe(300)
        ->and($updated->jobClass)->toBe($original->jobClass)
        ->and($updated->compensationJobClass)->toBe($original->compensationJobClass)
        ->and($updated->condition)->toBe($original->condition)
        ->and($updated->conditionNegated)->toBe($original->conditionNegated)
        ->and($updated->queue)->toBe($original->queue)
        ->and($updated->connection)->toBe($original->connection)
        ->and($updated->retry)->toBe($original->retry)
        ->and($updated->backoff)->toBe($original->backoff)
        ->and($updated->sync)->toBe($original->sync)
        ->and($original->timeout)->toBe(120);
});

it('retry/backoff/timeout chain with every other fluent method and preserve queue/connection when sync() is not called', function () {
    $step = StepDefinition::fromJobClass('App\\Jobs\\Process')
        ->onQueue('q')
        ->onConnection('c')
        ->retry(3)
        ->backoff(5)
        ->timeout(60)
        ->withCompensation('App\\Jobs\\Compensate');

    expect($step->jobClass)->toBe('App\\Jobs\\Process')
        ->and($step->queue)->toBe('q')
        ->and($step->connection)->toBe('c')
        ->and($step->retry)->toBe(3)
        ->and($step->backoff)->toBe(5)
        ->and($step->timeout)->toBe(60)
        ->and($step->compensationJobClass)->toBe('App\\Jobs\\Compensate')
        ->and($step->sync)->toBeFalse();
});

it('sync() preserves retry/backoff/timeout but clears queue/connection', function () {
    $step = StepDefinition::fromJobClass('App\\Jobs\\Process')
        ->onQueue('q')
        ->onConnection('c')
        ->retry(3)
        ->backoff(5)
        ->timeout(60)
        ->sync();

    expect($step->sync)->toBeTrue()
        ->and($step->queue)->toBeNull()
        ->and($step->connection)->toBeNull()
        ->and($step->retry)->toBe(3)
        ->and($step->backoff)->toBe(5)
        ->and($step->timeout)->toBe(60);
});
