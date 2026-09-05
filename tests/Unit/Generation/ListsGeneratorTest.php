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

/**
 * SPEC-010 AC6, deletion family — removals take elements from the END, so a candidate is a PREFIX
 * of the list it came from (D036), none falls below the declared minimum, and the shortest comes
 * first. Element shrinking is the second family and the next step; until it exists there are no
 * same-length candidates to order against, so that clause of AC6 is not asserted here.
 *
 * `shrinkValuesOf()` is defined in ElementsGeneratorTest.php.
 */
it('shrinks a list by removing elements from the end, shortest first', function () {
    $g = Gen::listsOf(Gen::integers(0, 100), 1, 4);

    // Seed 5 draws [11, 74, 44, 93]. The lengths shrink through the same integers(1, 4) that drew
    // them — the origin (here the minimum, 1) first, then halving back toward the value — so the
    // candidates are the one-element and three-element prefixes, in that order.
    $gv = $g->generate(Source::seeded(5));
    expect($gv->value)->toBe([11, 74, 44, 93]);

    $candidates = shrinkValuesOf($g, $gv);

    expect(array_slice($candidates, 0, 2))->toBe([[11], [11, 74, 44]]);

    foreach ($candidates as $candidate) {
        expect($candidate)->toBeArray();
        if (! is_array($candidate)) {
            continue;
        }

        expect(count($candidate))->toBeGreaterThanOrEqual(1)
            ->and($candidate)->not->toBe([11, 74, 44, 93]);

        if (count($candidate) < 4) {
            // A prefix is what a reader of a failure report can check by eye, and it matches how
            // SPEC-002 shrinks a command sequence by holding a prefix.
            expect($candidate)->toBe(array_slice([11, 74, 44, 93], 0, count($candidate)));
        }
    }
})->group('SPEC-010');

it('gives every candidate a context that permits further shrinking', function () {
    $g = Gen::listsOf(Gen::integers(0, 100), 1, 4);

    $gv = $g->generate(Source::seeded(5));

    // An item's context is opaque and cannot be reconstructed from the item, so a candidate that
    // lost its items' contexts would shrink no further — the silent kind of degradation that turns
    // a counterexample into a worse one with no signal. Shrinking each candidate in turn is what
    // proves the context survived the deletion.
    foreach ($g->shrink($gv) as $candidate) {
        expect(fn () => [...$g->shrink($candidate)])->not->toThrow(Throwable::class);
    }
})->group('SPEC-010');

/**
 * SPEC-010 AC6, element family — same-length candidates that shrink ONE element through the item
 * generator, offered only after every shorter candidate, and the walk that ends at a list of `min`
 * elements each at the item generator's origin. Delegation is the point: this class knows nothing
 * about integers, and an item that shrank by any other route would prove the wiring wrong.
 */
it('shrinks elements only after every shorter candidate, delegating to the item generator', function () {
    $g = Gen::listsOf(Gen::integers(0, 100), 1, 4);

    $gv = $g->generate(Source::seeded(5));
    $candidates = shrinkValuesOf($g, $gv);

    // Element 0 first, and its values are exactly integers(0, 100) shrinking 11: the origin, then
    // halving back toward 11. This class contributes the position; the item generator the value.
    $sameLength = array_values(array_filter(
        $candidates,
        static fn (mixed $candidate): bool => is_array($candidate) && count($candidate) === 4,
    ));

    expect(array_slice($sameLength, 0, 4))->toBe([
        [0, 74, 44, 93],
        [6, 74, 44, 93],
        [9, 74, 44, 93],
        [10, 74, 44, 93],
    ]);

    $sameLengthSeen = false;

    foreach ($candidates as $candidate) {
        expect($candidate)->toBeArray();
        if (! is_array($candidate)) {
            continue;
        }

        if (count($candidate) < 4) {
            // AC6's ordering clause, testable only now that same-length candidates exist.
            expect($sameLengthSeen)->toBeFalse();

            continue;
        }

        $sameLengthSeen = true;

        // One element at a time — the same one-component-at-a-time reduction associative() makes,
        // and the local minimum R3 documents: a bug needing two elements reduced together is not
        // found by this family.
        $differences = 0;
        foreach ([11, 74, 44, 93] as $position => $original) {
            if (($candidate[$position] ?? null) !== $original) {
                $differences++;
            }
        }

        expect($differences)->toBe(1);
    }
})->group('SPEC-010');

it('terminates at the minimum length with every element at the item generator\'s origin', function () {
    $g = Gen::listsOf(Gen::integers(0, 100), 1, 4);

    foreach (range(1, 20) as $seed) {
        $value = $g->generate(Source::seeded($seed));

        $steps = 0;
        while ($steps < 64) {
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

        // One element, at integers(0, 100)'s origin. The minimum comes from this generator, the
        // origin from the item generator — which is exactly the division of labour AC6 requires.
        expect($steps)->toBeLessThan(64)
            ->and($value->value)->toBe([0]);
    }
})->group('SPEC-010');
