<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-003 AC3 — elements() shrinks toward the first element, the first place a
 * GeneratedValue context becomes tangible (the chosen index).
 */

/**
 * The candidate values from shrinking $v, in order.
 *
 * @template T
 *
 * @param  Generator<T>  $g
 * @param  GeneratedValue<T>  $v
 * @return list<T>
 */
function shrinkValuesOf(Generator $g, GeneratedValue $v): array
{
    $out = [];

    foreach ($g->shrink($v) as $gv) {
        $out[] = $gv->value;
    }

    return $out;
}

it('generates one of the choices', function () {
    $g = Gen::elements(['a', 'b', 'c', 'd']);

    expect(['a', 'b', 'c', 'd'])->toContain($g->generate(Source::seeded(7))->value);
})->group('SPEC-003');

it('shrinks toward the first element, delegating the index to integers()', function () {
    $g = Gen::elements(['a', 'b', 'c', 'd']);

    // 'd' is at index 3; the index shrinks toward 0 like integers(0, 3): [0, 2].
    $fromD = new GeneratedValue('d', new GeneratedValue(3));

    expect(shrinkValuesOf($g, $fromD))->toBe(['a', 'c']);
})->group('SPEC-003');

it('carries the chosen index as the context so shrinking can continue', function () {
    $g = Gen::elements(['a', 'b', 'c', 'd']);
    $fromD = new GeneratedValue('d', new GeneratedValue(3));

    $candidates = [...$g->shrink($fromD)];

    expect($candidates[0]->context)->toBeInstanceOf(GeneratedValue::class);
})->group('SPEC-003');

it('deduplicates choices so a candidate is never the input value', function () {
    $g = Gen::elements(['x', 'x', 'y', 'y', 'z']);

    foreach (range(1, 30) as $seed) {
        $v = $g->generate(Source::seeded($seed));
        expect(shrinkValuesOf($g, $v))->not->toContain($v->value);
    }
})->group('SPEC-003');

it('normalizes a non-list array with array_values — keys are dropped, order kept', function () {
    $keyed = Gen::elements(['a' => 10, 'b' => 20, 'c' => 30]);
    $list = Gen::elements([10, 20, 30]);

    foreach (range(1, 20) as $seed) {
        expect($keyed->generate(Source::seeded($seed))->value)
            ->toBe($list->generate(Source::seeded($seed))->value);
    }
})->group('SPEC-003');

it('throws on an empty choices array', function () {
    expect(fn () => Gen::elements([]))->toThrow(InvalidArgumentException::class);
})->group('SPEC-003');

it('a single choice generates that value and does not shrink', function () {
    $g = Gen::elements(['only']);

    expect($g->generate(Source::seeded(1))->value)->toBe('only')
        ->and(shrinkValuesOf($g, new GeneratedValue('only', new GeneratedValue(0))))->toBe([]);
})->group('SPEC-003');

it('throws on a context of the wrong shape — a generator bug, not user input', function () {
    $g = Gen::elements(['a', 'b', 'c']);

    // The context should be the chosen index's GeneratedValue<int>; this is neither.
    $bogus = new GeneratedValue('b', 'not-an-index');

    expect(fn () => [...$g->shrink($bogus)])->toThrow(LogicException::class);
})->group('SPEC-003');
