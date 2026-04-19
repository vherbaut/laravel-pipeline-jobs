<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvocationStrategy;

it('declares exactly three cases named Default, Middleware, Action', function (): void {
    $names = array_map(
        static fn (StepInvocationStrategy $case): string => $case->name,
        StepInvocationStrategy::cases(),
    );

    expect($names)->toBe(['Default', 'Middleware', 'Action']);
});

it('returns cases in declaration order', function (): void {
    $cases = StepInvocationStrategy::cases();

    expect($cases[0])->toBe(StepInvocationStrategy::Default)
        ->and($cases[1])->toBe(StepInvocationStrategy::Middleware)
        ->and($cases[2])->toBe(StepInvocationStrategy::Action);
});

it('is a pure (non-backed) enum without a value accessor', function (): void {
    $reflection = new ReflectionEnum(StepInvocationStrategy::class);

    expect($reflection->isBacked())->toBeFalse();
});
