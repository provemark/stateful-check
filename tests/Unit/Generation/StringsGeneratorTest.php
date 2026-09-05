<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-010 AC3 — strings() draws its characters from the alphabet it was given and its length,
 * counted in CODE POINTS, from its bounds (D031).
 *
 * The second alphabet is the criterion's teeth: 'é' is two bytes and '😀' is four, so a generator
 * that counted with strlen() would satisfy the ASCII case and quietly emit values that violate the
 * bounds a consumer derived from a JSON Schema — an invalid value presented as valid, the one
 * failure this layer may never produce. Counting here is done with preg over /u for the same
 * reason the generator will use it: no ext-mbstring, so no runtime dependency (R7).
 *
 * @return list<string>
 */
function charactersOf(string $value): array
{
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

    return $characters === false ? [] : $characters;
}

/** @return list<int> */
function ac3Seeds(): array
{
    return range(1, 40);
}

it('draws only alphabet characters, within bounds counted in code points', function () {
    $cases = [
        [Gen::strings(3, 6, ['a', 'b', 'c']), ['a', 'b', 'c'], 3, 6],
        [Gen::strings(0, 4, ['a', 'é', '😀']), ['a', 'é', '😀'], 0, 4],
    ];

    foreach ($cases as [$g, $alphabet, $min, $max]) {
        foreach (ac3Seeds() as $seed) {
            $value = $g->generate(Source::seeded($seed))->value;

            expect($value)->toBeString();
            if (! is_string($value)) {
                continue;
            }

            $characters = charactersOf($value);

            foreach ($characters as $character) {
                expect($alphabet)->toContain($character);
            }

            expect(count($characters))->toBeGreaterThanOrEqual($min)
                ->and(count($characters))->toBeLessThanOrEqual($max);
        }
    }
})->group('SPEC-010');

it('reaches both of its length bounds across the seeds', function () {
    $cases = [
        [Gen::strings(3, 6, ['a', 'b', 'c']), 3, 6],
        [Gen::strings(0, 4, ['a', 'é', '😀']), 0, 4],
    ];

    foreach ($cases as [$g, $min, $max]) {
        $lengths = [];
        foreach (ac3Seeds() as $seed) {
            $value = $g->generate(Source::seeded($seed))->value;
            $lengths[] = is_string($value) ? count(charactersOf($value)) : -1;
        }

        // A generator that never reaches its bounds is not wrong about any single value but is
        // useless where bugs actually sit — at the empty string and at the maximum length.
        expect($lengths)->toContain($min)
            ->and($lengths)->toContain($max);
    }
})->group('SPEC-010');

/**
 * SPEC-010 AC4, deletion family — shortening comes first, a candidate is a PREFIX of the value it
 * came from (D036, a deliberate divergence from fast-check, which keeps the suffix), and no
 * candidate falls below the length bound. Character simplification is the next step; until it
 * exists there are no same-length candidates to order against, so that clause of AC4 is not
 * asserted here.
 *
 * `shrinkValuesOf()` is defined in ElementsGeneratorTest.php.
 */
it('shrinks by removing characters from the end, shortest first, never below the minimum', function () {
    $g = Gen::strings(3, 6, ['a', 'b', 'c']);

    // Seed 5 draws 'abacbb', the maximum length. The lengths shrink through the same
    // integers(3, 6) model the length was drawn with — the origin first, then halving back toward
    // the value — so the candidates are the length-3 and length-5 prefixes, in that order.
    $gv = $g->generate(Source::seeded(5));
    expect($gv->value)->toBe('abacbb');

    $candidates = shrinkValuesOf($g, $gv);

    expect($candidates)->toBe(['aba', 'abacb']);

    foreach ($candidates as $candidate) {
        expect($candidate)->toBeString();
        if (! is_string($candidate)) {
            continue;
        }

        // A prefix is what a reader of a failure report can check by eye, and it is the same
        // mental model SPEC-002 uses when it holds a prefix of a command sequence.
        expect(str_starts_with('abacbb', $candidate))->toBeTrue()
            ->and(count(charactersOf($candidate)))->toBeGreaterThanOrEqual(3)
            ->and($candidate)->not->toBe('abacbb');
    }
})->group('SPEC-010');

it('cuts its prefixes on characters, never on bytes', function () {
    $g = Gen::strings(0, 4, ['a', 'é', '😀']);

    // Seed 2 draws 'a😀a': three characters, six bytes. A byte-wise prefix of length two would cut
    // the astral character in half and produce a string that is not valid UTF-8 — a value this
    // generator can never legitimately produce, and the exact failure D031 is about.
    $gv = $g->generate(Source::seeded(2));
    expect($gv->value)->toBe('a😀a');

    $candidates = shrinkValuesOf($g, $gv);

    expect($candidates)->toBe(['', 'a😀']);

    foreach ($candidates as $candidate) {
        expect($candidate)->toBeString();
        if (! is_string($candidate)) {
            continue;
        }

        expect(preg_match('//u', $candidate))->toBe(1);
    }
})->group('SPEC-010');
