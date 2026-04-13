<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;

it('is an interface, not a class or trait', function () {
    $reflection = new ReflectionClass(CompensableJob::class);

    expect($reflection->isInterface())->toBeTrue();
});

it('declares a single compensate method with PipelineContext parameter and void return', function () {
    $reflection = new ReflectionClass(CompensableJob::class);
    $methods = $reflection->getMethods();

    expect($methods)->toHaveCount(1)
        ->and($methods[0]->getName())->toBe('compensate');

    $parameters = $methods[0]->getParameters();
    expect($parameters)->toHaveCount(1)
        ->and((string) $parameters[0]->getType())->toBe(PipelineContext::class)
        ->and((string) $methods[0]->getReturnType())->toBe('void');
});

it('accepts implementers that satisfy the compensate contract', function () {
    $compensator = new class implements CompensableJob
    {
        public bool $called = false;

        public function compensate(PipelineContext $context): void
        {
            $this->called = true;
        }
    };

    $compensator->compensate(new PipelineContext);

    expect($compensator->called)->toBeTrue();
});
