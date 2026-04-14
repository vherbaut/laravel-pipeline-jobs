<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
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

it('accepts both single-argument and two-argument compensate() implementations when called with two arguments', function (): void {
    $singleArg = new class implements CompensableJob
    {
        public int $invocations = 0;

        public function compensate(PipelineContext $context): void
        {
            $this->invocations++;
        }
    };

    $twoArg = new class implements CompensableJob
    {
        public ?FailureContext $lastFailure = null;

        public function compensate(PipelineContext $context, ?FailureContext $failure = null): void
        {
            $this->lastFailure = $failure;
        }
    };

    $context = new PipelineContext;
    $failure = new FailureContext('App\\Jobs\\StepOne', 0, new RuntimeException('boom'));

    $singleArg->compensate($context, $failure);
    $twoArg->compensate($context, $failure);

    expect($singleArg->invocations)->toBe(1)
        ->and($twoArg->lastFailure)->toBe($failure);
});
