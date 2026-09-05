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

    // The head of the sequence is the deletion family: exactly the length-3 and length-5 prefixes.
    // What follows are the same-length simplifications, whose contents are the next test's
    // subject — asserting the whole list here would only duplicate it.
    expect(array_slice($candidates, 0, 2))->toBe(['aba', 'abacb']);

    $sameLengthSeen = false;

    foreach ($candidates as $candidate) {
        expect($candidate)->toBeString();
        if (! is_string($candidate)) {
            continue;
        }

        $length = count(charactersOf($candidate));

        expect($length)->toBeGreaterThanOrEqual(3)
            ->and($candidate)->not->toBe('abacbb');

        if ($length === 6) {
            $sameLengthSeen = true;

            continue;
        }

        // AC4's ordering clause: every shorter candidate comes before every same-length one. It
        // became testable only once the simplification family existed, so it lands here rather
        // than in the step that built the deletions.
        expect($sameLengthSeen)->toBeFalse();

        // A prefix is what a reader of a failure report can check by eye, and it is the same
        // mental model SPEC-002 uses when it holds a prefix of a command sequence (D036).
        expect(str_starts_with('abacbb', $candidate))->toBeTrue();
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

    // Again only the deletion head is pinned: the same-length candidates that follow replace one
    // character rather than cutting any, so they cannot expose a byte-wise cut.
    expect(array_slice($candidates, 0, 2))->toBe(['', 'a😀']);

    foreach ($candidates as $candidate) {
        expect($candidate)->toBeString();
        if (! is_string($candidate)) {
            continue;
        }

        // No candidate may be malformed UTF-8 — the failure a byte-wise operation would produce.
        expect(preg_match('//u', $candidate))->toBe(1);

        if (count(charactersOf($candidate)) < 3) {
            expect(str_starts_with('a😀a', $candidate))->toBeTrue();
        }
    }
})->group('SPEC-010');

/**
 * SPEC-010 AC4, simplification family — same-length candidates that move ONE position toward the
 * origin's character, offered only after every shorter candidate. With no explicit origin that
 * character is the alphabet's first, and the walk ends at minLength repetitions of it.
 *
 * Shortening first is the whole value of the shrink for a report: a reader learns far more from
 * "it fails at any 3-character name" than from a 6-character one with simpler letters.
 */
it('offers every shorter candidate before any same-length simplification', function () {
    $g = Gen::strings(3, 6, ['a', 'b', 'c']);
    $gv = $g->generate(Source::seeded(5));

    // 'abacbb' — the two prefixes first, then one position at a time from the left, each index
    // shrunk through the same integers(0, 2) that drew it: 'b' (index 1) reduces to 'a', 'c'
    // (index 2) reduces to 'a' and then to 'b'. Positions already at the first character offer
    // nothing, which is why positions 0 and 2 are absent.
    expect(shrinkValuesOf($g, $gv))->toBe([
        'aba',
        'abacb',
        'aaacbb',
        'abaabb',
        'ababbb',
        'abacab',
        'abacba',
    ]);
})->group('SPEC-010');

it('terminates at minLength repetitions of the first character when the first candidate is followed', function () {
    $g = Gen::strings(3, 6, ['a', 'b', 'c']);

    foreach (range(1, 20) as $seed) {
        $value = $g->generate(Source::seeded($seed));

        // The greedy loop always takes the first candidate; this is that walk, bounded so a future
        // ordering change cannot turn shrinking into a long or endless one without failing a test.
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
            ->and($value->value)->toBe('aaa');
    }
})->group('SPEC-010');

/**
 * SPEC-010 AC4, explicit origin (D032) — a caller may aim the shrink at a meaningful string, such
 * as a JSON Schema `default`. D040 requires its characters to come from the alphabet, so every
 * candidate stays a value the generator could itself have produced.
 */
it('shrinks to an explicit origin when the value is at least as long as it', function () {
    $g = Gen::strings(3, 6, ['a', 'b', 'c', 'd', 'm', 'i', 'n'], origin: 'admin');

    $value = $g->generate(Source::seeded(5));
    expect($value->value)->toBe('icdnbb');

    $lengths = [];
    $steps = 0;
    while ($steps < 32) {
        $next = null;
        foreach ($g->shrink($value) as $candidate) {
            expect($candidate->value)->toBeString();
            if (is_string($candidate->value)) {
                $lengths[] = count(charactersOf($candidate->value));
            }
            $next = $next ?? $candidate;
        }

        if ($next === null) {
            break;
        }

        $value = $next;
        $steps++;
    }

    expect($steps)->toBeLessThan(32)
        ->and($value->value)->toBe('admin');

    // The deletion family's floor is the origin's length (five), not minLength (three): a shorter
    // candidate could never reach the origin again, since shrinking does not grow a value.
    foreach ($lengths as $length) {
        expect($length)->toBeGreaterThanOrEqual(5);
    }
})->group('SPEC-010');

it('never grows a value shorter than the origin, ending at the origin\'s prefix', function () {
    $g = Gen::strings(3, 6, ['a', 'b', 'c', 'd', 'm', 'i', 'n'], origin: 'admin');

    $value = $g->generate(Source::seeded(2));
    expect($value->value)->toBe('dmc');

    $steps = 0;
    while ($steps < 32) {
        $next = null;
        foreach ($g->shrink($value) as $candidate) {
            expect($candidate->value)->toBeString();
            if (is_string($candidate->value) && is_string($value->value)) {
                // Growing is not a reduction. A candidate longer than the value it came from would
                // also break the greedy loop's assumption that each step gets smaller.
                expect(count(charactersOf($candidate->value)))
                    ->toBeLessThanOrEqual(count(charactersOf($value->value)));
            }
            $next = $next ?? $candidate;
        }

        if ($next === null) {
            break;
        }

        $value = $next;
        $steps++;
    }

    // Three characters cannot become 'admin', so the walk ends at the origin's three-character
    // prefix — the terminus the amended AC4 records rather than leaving to be found as a bug.
    expect($steps)->toBeLessThan(32)
        ->and($value->value)->toBe('adm');
})->group('SPEC-010');
