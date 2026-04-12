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
