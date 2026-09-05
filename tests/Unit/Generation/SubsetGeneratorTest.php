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
