<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vherbaut\LaravelPipelineJobs\Execution\ParallelContextMerger;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;

it('merges non-conflicting enrichments from two sub-steps into the baseline', function (): void {
    $baseline = new SimpleContext;

    $finalFromA = new SimpleContext;
    $finalFromA->name = 'from-a';

    $finalFromB = new SimpleContext;
    $finalFromB->count = 42;

    $merged = ParallelContextMerger::merge($baseline, [$finalFromA, $finalFromB]);

    expect($merged)->toBeInstanceOf(SimpleContext::class)
        ->and($merged->name)->toBe('from-a')
        ->and($merged->count)->toBe(42);
});

it('resolves conflicts in declaration order with a Log::warning entry', function (): void {
    Log::spy();

    $baseline = new SimpleContext;
    $baseline->name = 'initial';

    $finalFromA = new SimpleContext;
    $finalFromA->name = 'from-a';

    $finalFromB = new SimpleContext;
    $finalFromB->name = 'from-b';

    $merged = ParallelContextMerger::merge($baseline, [$finalFromA, $finalFromB], 'pipe-123', 2);

    expect($merged->name)->toBe('from-b');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Pipeline parallel context merge conflict', Mockery::on(function (array $context): bool {
            return $context['pipelineId'] === 'pipe-123'
                && $context['groupIndex'] === 2
                && $context['propertyName'] === 'name'
                && $context['previousSubIndex'] === 0
                && $context['overridingSubIndex'] === 1;
        }));
});

it('ignores null values from a sub-step that did not enrich the property', function (): void {
    $baseline = new SimpleContext;
    $baseline->name = 'baseline';

    // finalFromA carries its own baseline snapshot (no mutation) — name still 'baseline'.
    $finalFromA = new SimpleContext;
    $finalFromA->name = 'baseline';

    // finalFromB explicitly enriches $name.
    $finalFromB = new SimpleContext;
    $finalFromB->name = 'enriched-by-b';

    $merged = ParallelContextMerger::merge($baseline, [$finalFromA, $finalFromB]);

    expect($merged->name)->toBe('enriched-by-b');
});

it('preserves the baseline when no sub-step enriched any property', function (): void {
    $baseline = new SimpleContext;
    $baseline->name = 'baseline';
    $baseline->count = 7;

    // Both sub-steps returned contexts identical to baseline (no mutations).
    $finalFromA = new SimpleContext;
    $finalFromA->name = 'baseline';
    $finalFromA->count = 7;

    $finalFromB = new SimpleContext;
    $finalFromB->name = 'baseline';
    $finalFromB->count = 7;

    $merged = ParallelContextMerger::merge($baseline, [$finalFromA, $finalFromB]);

    expect($merged->name)->toBe('baseline')
        ->and($merged->count)->toBe(7)
        ->and($merged)->not->toBe($baseline); // returned instance is a fresh clone
});

it('returns null when the baseline is null (context-less pipeline)', function (): void {
    expect(ParallelContextMerger::merge(null, []))->toBeNull();
});
