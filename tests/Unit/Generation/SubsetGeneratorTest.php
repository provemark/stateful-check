<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-010 AC9 — subsetOf() draws distinct members of a fixed choice set.
 *
 * It exists for one consumer criterion, JSON Schema's uniqueItems, and covers it only where the
 * item schema is a finite, enumerable set (D034). It is NOT a general uniqueness combinator: the
 * prior art shows fast-check leaving shrink candidates below the requested length and Hypothesis
 * aborting the test case, and neither is available to a consumer whose whole promise is that every
 * value it generates satisfies its schema. Shrinking is the next step.
 */
it('draws distinct choices within its size bounds, reaching both', function () {
    $g = Gen::subsetOf(['a', 'b', 'c'], 1, 3);

    $sizes = [];
    foreach (range(1, 40) as $seed) {
        $value = $g->generate(Source::seeded($seed))->value;

        expect($value)->toBeArray();
        if (! is_array($value)) {
            continue;
        }

        expect(array_is_list($value))->toBeTrue()
            ->and(count($value))->toBeGreaterThanOrEqual(1)
            ->and(count($value))->toBeLessThanOrEqual(3);

        // Distinctness is the whole point: a repeated choice would be a value that violates the
        // schema it was derived from, presented as valid.
        expect(count(array_unique($value, SORT_REGULAR)))->toBe(count($value));

        foreach ($value as $item) {
            expect(['a', 'b', 'c'])->toContain($item);
        }

        $sizes[] = count($value);
    }

    expect($sizes)->toContain(1)
        ->and($sizes)->toContain(3);
})->group('SPEC-010');

/**
 * SPEC-010 AC9, shrinking — removals first, then the remaining choices move toward earlier ones,
 * never below the minimum size and never producing a duplicate at any point in the sequence.
 *
 * `shrinkValuesOf()` is defined in ElementsGeneratorTest.php.
 */
it('removes before moving choices earlier, and never falls below the minimum', function () {
    $g = Gen::subsetOf(['a', 'b', 'c'], 1, 3);

    // Seed 1 draws ['c', 'b'] — indices [2, 1]. The size shrinks through integers(1, 3) to 1, so
    // the prefix comes first; then position 0 moves from 'c' to 'a' (index 2 toward 0) and
    // position 1 from 'b' to 'a'. Moving position 0 to 'b' is skipped: 'b' is already there.
    $gv = $g->generate(Source::seeded(1));
    expect($gv->value)->toBe(['c', 'b']);

    expect(shrinkValuesOf($g, $gv))->toBe([
        ['c'],
        ['a', 'b'],
        ['c', 'a'],
    ]);
})->group('SPEC-010');

it('skips every candidate that would repeat a choice', function () {
    $g = Gen::subsetOf(['a', 'b', 'c'], 1, 3);

    // Seed 5 draws all three choices, so every earlier index a position could move to is already
    // taken: the whole simplification family collapses and only the removals remain. Skipping the
    // colliding candidates is what keeps the promise "never a duplicate at any point" — filtering
    // them out afterwards would have counted them as offered.
    $gv = $g->generate(Source::seeded(5));
    expect($gv->value)->toBe(['a', 'c', 'b']);

    $candidates = shrinkValuesOf($g, $gv);

    expect($candidates)->toBe([['a'], ['a', 'c']]);

    foreach ($candidates as $candidate) {
        expect($candidate)->toBeArray();
        if (! is_array($candidate)) {
            continue;
        }

        expect(count(array_unique($candidate, SORT_REGULAR)))->toBe(count($candidate))
            ->and(count($candidate))->toBeGreaterThanOrEqual(1);
    }
})->group('SPEC-010');

it('terminates at the minimum size on the first choice', function () {
    $g = Gen::subsetOf(['a', 'b', 'c'], 1, 3);

    foreach (range(1, 20) as $seed) {
        $value = $g->generate(Source::seeded($seed));

        $steps = 0;
        while ($steps < 32) {
            $next = null;
            foreach ($g->shrink($value) as $candidate) {
                $next = $candidate;
                break;
            }

            if ($next === null) {
                break;
            }

            $value = $next;
            $steps++;
        }

        expect($steps)->toBeLessThan(32)
            ->and($value->value)->toBe(['a']);
    }
})->group('SPEC-010');

/**
 * SPEC-010 AC10 (subsets) — invalid arguments throw at CONSTRUCTION. The interesting case is a
 * minimum larger than the choice set: it is not malformed, it is UNSATISFIABLE, because there are
 * not that many distinct choices to draw. Left unchecked it would fail during generation, where
 * the seed would be blamed for a mistake the caller made.
 */
it('rejects an empty choice set, an inverted range and an unsatisfiable minimum', function () {
    expect(fn () => Gen::subsetOf([], 0, 1))
        ->toThrow(InvalidArgumentException::class, 'subsetOf(): choices must not be empty.')
        ->and(fn () => Gen::subsetOf(['a', 'b'], 2, 1))
        ->toThrow(InvalidArgumentException::class, 'subsetOf(): max (1) is below min (2).')
        ->and(fn () => Gen::subsetOf(['a'], 2, 3))
        ->toThrow(InvalidArgumentException::class, 'subsetOf(): min (2) exceeds the 1 available choices.')
        ->and(fn () => Gen::subsetOf(['a', 'b'], -1, 1))
        ->toThrow(InvalidArgumentException::class, 'subsetOf(): min (-1) must not be negative.');
})->group('SPEC-010');
