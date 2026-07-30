<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-003 AC3 — map() applies a function on generation and delegates shrinking to the
 * inner generator, re-applying the function. A non-injective function can map a shrunk
 * inner value back to the input value; those candidates are filtered so a candidate is
 * never the input value (strict ===, as with elements' dedup — see NOTES).
 *
 * shrinkValuesOf() is defined in ElementsGeneratorTest.php and shared across the suite.
 */
it('applies the function on generation', function () {
    $g = Gen::map(fn (int $n) => $n * 10, Gen::integers(0, 3));

    expect([0, 10, 20, 30])->toContain($g->generate(Source::seeded(4))->value);
})->group('SPEC-003');

it('shrinks by delegating to the inner generator and re-applying the function', function () {
    $g = Gen::map(fn (int $n) => $n * 10, Gen::integers(0, 64));

    // inner 8 shrinks like integers(0, 64): [0, 4, 6, 7] → mapped [0, 40, 60, 70].
    $input = new GeneratedValue(80, new GeneratedValue(8));

    expect(shrinkValuesOf($g, $input))->toBe([0, 40, 60, 70]);
})->group('SPEC-003');

it('filters candidates whose mapped value equals the input (non-injective function)', function () {
    $g = Gen::map(fn (int $n) => $n % 2, Gen::integers(0, 100));

    // inner 50 maps to 0; several shrunk inners also map to 0 and must be dropped, so
    // that no candidate equals the input value.
    $input = new GeneratedValue(0, new GeneratedValue(50));

    expect(shrinkValuesOf($g, $input))->not->toContain($input->value);
})->group('SPEC-003');

it('carries the inner GeneratedValue as the context so shrinking can continue', function () {
    $g = Gen::map(fn (int $n) => $n * 10, Gen::integers(0, 64));
    $input = new GeneratedValue(80, new GeneratedValue(8));

    $candidates = [...$g->shrink($input)];

    expect($candidates[0]->context)->toBeInstanceOf(GeneratedValue::class);
})->group('SPEC-003');

it('throws on a context of the wrong shape — a generator bug, not user input', function () {
    $g = Gen::map(fn (int $n) => $n, Gen::integers(0, 5));

    expect(fn () => [...$g->shrink(new GeneratedValue(3, 'not-a-generated-value'))])
        ->toThrow(LogicException::class);
})->group('SPEC-003');

it('map over a constant does not shrink', function () {
    $g = Gen::map(fn (string $s) => "[$s]", Gen::constant('c'));
    $input = new GeneratedValue('[c]', new GeneratedValue('c'));

    expect([...$g->shrink($input)])->toBe([]);
})->group('SPEC-003');
