<?php

declare(strict_types=1);

use Vherbaut\LaravelPipelineJobs\PendingPipelineDispatch;
use Vherbaut\LaravelPipelineJobs\PipelineBuilder;

/**
 * Reflection-based parity test ensuring PendingPipelineDispatch exposes every
 * non-terminal public method declared on PipelineBuilder.
 *
 * When a future story adds a new fluent method to PipelineBuilder, this test
 * fails until the proxy is mirrored onto PendingPipelineDispatch. The allowlist
 * below captures the methods intentionally excluded from the dispatch surface
 * per AC #6 / AC #7 of Story 7.3 and the wrapper's own lifecycle methods.
 */

/** Methods on PipelineBuilder that must NOT be proxied (spec-mandated exclusions). */
const DISPATCH_PROXY_EXCLUDED_BUILDER_METHODS = [
    'run',          // dispatch() IS the execution verb (AC #6)
    'build',        // internal definition verb (AC #7)
    'toListener',   // listen() is the registration verb (AC #7)
    'return',       // dispatch() discards the return value (AC #6)
    'getContext',   // context inspection belongs on a retained builder (AC #7)
    'reverse',      // reverse() returns a NEW PipelineBuilder, incompatible with PendingPipelineDispatch's private readonly builder reference (Story 9.2 AC #13)
];

/** Non-proxy methods on PendingPipelineDispatch (lifecycle + control, not mirrored from the builder). */
const DISPATCH_WRAPPER_NON_PROXY_METHODS = [
    '__construct',
    '__destruct',
    '__clone',
    '__serialize',
    'cancel',
];

it('PendingPipelineDispatch proxies every non-terminal public method on PipelineBuilder', function (): void {
    $builderMethods = collect((new ReflectionClass(PipelineBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $m) => $m->isStatic() || $m->isConstructor())
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->reject(fn (string $name) => in_array($name, DISPATCH_PROXY_EXCLUDED_BUILDER_METHODS, true))
        ->values()
        ->all();

    $wrapperMethods = collect((new ReflectionClass(PendingPipelineDispatch::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->all();

    $missing = array_values(array_diff($builderMethods, $wrapperMethods));

    expect($missing)->toBe([], sprintf(
        'PipelineBuilder exposes public method(s) [%s] that are not proxied on PendingPipelineDispatch. '
        .'Either add the proxy method or add the method to DISPATCH_PROXY_EXCLUDED_BUILDER_METHODS with an AC reference.',
        implode(', ', $missing),
    ));
});

it('PendingPipelineDispatch does NOT expose any of the excluded terminal builder methods', function (): void {
    $wrapperMethods = collect((new ReflectionClass(PendingPipelineDispatch::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->all();

    foreach (DISPATCH_PROXY_EXCLUDED_BUILDER_METHODS as $excluded) {
        expect($wrapperMethods)->not->toContain($excluded, sprintf(
            'PendingPipelineDispatch must not expose the terminal method %s() per AC #6/#7.',
            $excluded,
        ));
    }
});

it('PendingPipelineDispatch does NOT expose reverse() because reverse returns a NEW PipelineBuilder (Story 9.2 AC #13)', function (): void {
    expect(method_exists(PendingPipelineDispatch::class, 'reverse'))->toBeFalse();
});

it('every PendingPipelineDispatch public method is either a proxy or a declared non-proxy', function (): void {
    $builderMethods = collect((new ReflectionClass(PipelineBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $m) => $m->isStatic() || $m->isConstructor())
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->all();

    $wrapperMethods = collect((new ReflectionClass(PendingPipelineDispatch::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->all();

    $unknown = array_values(array_diff(
        $wrapperMethods,
        $builderMethods,
        DISPATCH_WRAPPER_NON_PROXY_METHODS,
    ));

    expect($unknown)->toBe([], sprintf(
        'PendingPipelineDispatch exposes public method(s) [%s] that are neither proxies nor in DISPATCH_WRAPPER_NON_PROXY_METHODS. '
        .'Declare the intent explicitly to avoid drift.',
        implode(', ', $unknown),
    ));
});
