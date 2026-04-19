<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineException;

it('extends PipelineException so generic catches still match', function (): void {
    $exception = InvalidPipelineDefinition::stepClassMissingInvocationMethod('Foo\\Bar');

    expect($exception)->toBeInstanceOf(PipelineException::class);
});

it('formats stepClassMissingInvocationMethod with the offending class name', function (): void {
    $exception = InvalidPipelineDefinition::stepClassMissingInvocationMethod('Foo\\Bar');

    expect($exception->getMessage())->toBe(
        'Pipeline step class "Foo\\Bar" must define handle() (single-arg or middleware-shape handle($passable, Closure $next)) or __invoke().',
    );
});
