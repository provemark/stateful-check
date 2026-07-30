<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;

/**
 * SPEC-003 AC2 — integer shrinking terminates and reaches the origin.
 *
 * Binary reduction toward a configurable origin (D005); origin clamp/throw (D015);
 * range-width overflow guard (D016). Shrinking is a pure function of the value — no
 * Source, no randomness.
 */

/**
 * The candidate values from shrinking $value, in order.
 *
 * @param  Generator<int>  $g
 * @return list<int>
 */
function shrinkInts(Generator $g, int $value): array
{
    $out = [];

    foreach ($g->shrink(new GeneratedValue($value)) as $gv) {
        $out[] = $gv->value;
    }

    return $out;
}

/**
 * Greedily follow the first candidate until none remain.
 *
 * @param  Generator<int>  $g
 * @return list<int>
 */
function firstCandidateChain(Generator $g, int $value): array
{
    $chain = [];

    while (($cands = shrinkInts($g, $value)) !== []) {
        $value = $cands[0];
        $chain[] = $value;
    }

    return $chain;
}

it('produces the exact halving sequence, origin first, from either side', function () {
    // gap 8 toward origin 0: origin, then 8-4, 8-2, 8-1
    expect(shrinkInts(Gen::integers(0, 64), 8))->toBe([0, 4, 6, 7])
        ->and(shrinkInts(Gen::integers(-64, 0), -8))->toBe([0, -4, -6, -7])
        ->and(shrinkInts(Gen::integers(0, 100, 20), 28))->toBe([20, 24, 26, 27])
        ->and(shrinkInts(Gen::integers(0, 100, 20), 12))->toBe([20, 16, 14, 13]);

    // negative distance behaves the same (intdiv truncates toward zero)

    // non-zero origin, value on each side of it
})->group('SPEC-003');

it('reaches the origin and terminates when the first candidate is taken', function () {
    expect(firstCandidateChain(Gen::integers(-100, 100), 50))->toBe([0])
        ->and(firstCandidateChain(Gen::integers(-100, 100), -50))->toBe([0])
        ->and(firstCandidateChain(Gen::integers(0, 100, 20), 60))->toBe([20]);
})->group('SPEC-003');

it('yields no candidates for a value already at the origin', function () {
    expect(shrinkInts(Gen::integers(0, 100), 0))->toBe([])
        ->and(shrinkInts(Gen::integers(0, 100, 20), 20))->toBe([]);
})->group('SPEC-003');

it('never yields the input and never a duplicate', function () {
    $g = Gen::integers(-1000, 1000);

    foreach ([1, 7, 50, 999, -1, -333, -1000] as $v) {
        $c = shrinkInts($g, $v);
        expect($c)->not->toContain($v)
            ->and($c)->toBe(array_values(array_unique($c)));
    }
})->group('SPEC-003');

it('shrinks deterministically — same candidates in the same order every time', function () {
    $g = Gen::integers(-1000, 1000);

    expect(shrinkInts($g, 617))->toBe(shrinkInts($g, 617));
})->group('SPEC-003');

it('produces a logarithmic number of candidates, strictly approaching the value', function () {
    $g = Gen::integers(0, PHP_INT_MAX);
    $v = 1_000_000;
    $c = shrinkInts($g, $v);

    expect($c[0])->toBe(0)
        ->and(count($c))->toBeLessThanOrEqual((int) floor(log($v, 2)) + 1);                                   // origin first

    for ($i = 1, $n = count($c); $i < $n; $i++) {
        expect(abs($c[$i]))->toBeGreaterThan(abs($c[$i - 1]))
            ->and($c[$i])->toBeLessThan($v);  // farther from origin
        // closer to the value
    }
})->group('SPEC-003');

it('shrinks a gap above 2^62 with repeated halving, not exponentiation', function () {
    $g = Gen::integers(0, PHP_INT_MAX);
    $v = 1 << 62;   // gap = 2^62; 2 ** k would become a float and intdiv() would throw

    $c = shrinkInts($g, $v);

    expect($c[0])->toBe(0)
        ->and(count($c))->toBeLessThanOrEqual((int) floor(log($v, 2)) + 1);
})->group('SPEC-003');

it('clamps an implicit (null) origin into the range, and uses an explicit one as given', function () {
    // default origin 0 is out of [10, 20] → clamped to 10
    expect(shrinkInts(Gen::integers(10, 20), 18)[0])->toBe(10)
        ->and(shrinkInts(Gen::integers(10, 20), 10))->toBe([])
        ->and(shrinkInts(Gen::integers(0, 100, 50), 80)[0])->toBe(50);

    // explicit in-range origin used verbatim
})->group('SPEC-003');

it('throws when an explicit origin is out of range', function () {
    expect(fn () => Gen::integers(10, 20, 0))->toThrow(InvalidArgumentException::class);
})->group('SPEC-003');

it('throws when the range width exceeds PHP_INT_MAX (D016)', function () {
    expect(fn () => Gen::integers(PHP_INT_MIN, PHP_INT_MAX))->toThrow(InvalidArgumentException::class);
})->group('SPEC-003');

it('accepts a range whose width is exactly PHP_INT_MAX', function () {
    // Width PHP_INT_MAX - 0 fits, so this must construct rather than throw (D016).
    expect(Gen::integers(0, PHP_INT_MAX))->toBeInstanceOf(Generator::class);
})->group('SPEC-003');

it('throws on a non-int value — the covariant contract narrows at runtime (D017)', function () {
    // shrink() takes GeneratedValue<mixed> since D017; a non-int value is a generator
    // bug, not user input, so it fails loudly rather than doing arithmetic on a string.
    expect(fn () => [...Gen::integers(0, 10)->shrink(new GeneratedValue('not-an-int'))])
        ->toThrow(LogicException::class);
})->group('SPEC-003');
