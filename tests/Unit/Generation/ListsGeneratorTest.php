<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-010 AC5 — listsOf() draws a length from its bounds and that many values from its item
 * generator. The same idea as strings(), which is why both live in one spec: a string is a
 * sequence of n characters, a list a sequence of n items, and n is drawn like any other bounded
 * integer. Shrinking is AC6.
 */

/** @return list<int> */
function ac5Seeds(): array
{
    return range(1, 40);
}

it('draws a list of items from its generator, within its length bounds', function () {
    $g = Gen::listsOf(Gen::integers(0, 100), 1, 4);

    foreach (ac5Seeds() as $seed) {
        $value = $g->generate(Source::seeded($seed))->value;

        expect($value)->toBeArray();
        if (! is_array($value)) {
            continue;
        }

        // A list, not a map: the keys are 0..n-1 in order, which is what every consumer of a
        // generated array assumes and what array_is_list() states outright.
        expect(array_is_list($value))->toBeTrue()
            ->and(count($value))->toBeGreaterThanOrEqual(1)
            ->and(count($value))->toBeLessThanOrEqual(4);

        foreach ($value as $item) {
            expect($item)->toBeInt()
                ->and($item)->toBeGreaterThanOrEqual(0)
                ->and($item)->toBeLessThanOrEqual(100);
        }
    }
})->group('SPEC-010');

it('reaches both of its length bounds across the seeds', function () {
    $g = Gen::listsOf(Gen::integers(0, 100), 1, 4);

    $lengths = [];
    foreach (ac5Seeds() as $seed) {
        $value = $g->generate(Source::seeded($seed))->value;
        $lengths[] = is_array($value) ? count($value) : -1;
    }

    // The bounds are where bugs sit — the shortest list a caller allows and the longest — so a
    // generator that never produces them is useless without being wrong about any single value.
    expect($lengths)->toContain(1)
        ->and($lengths)->toContain(4);
})->group('SPEC-010');
