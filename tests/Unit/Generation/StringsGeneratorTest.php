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
