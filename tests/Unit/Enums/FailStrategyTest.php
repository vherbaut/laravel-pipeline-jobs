<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;

it('exposes exactly three cases in the documented order', function () {
    $cases = FailStrategy::cases();

    expect($cases)->toHaveCount(3)
        ->and($cases[0]->name)->toBe('StopAndCompensate')
        ->and($cases[1]->name)->toBe('SkipAndContinue')
        ->and($cases[2]->name)->toBe('StopImmediately');
});

it('is a pure enum (not backed)', function () {
    $reflection = new ReflectionEnum(FailStrategy::class);

    expect($reflection->isBacked())->toBeFalse();
});

it('cases are identity-comparable via ===', function () {
    expect(FailStrategy::StopImmediately)->toBe(FailStrategy::StopImmediately)
        ->and(FailStrategy::StopAndCompensate)->not->toBe(FailStrategy::SkipAndContinue);
});
