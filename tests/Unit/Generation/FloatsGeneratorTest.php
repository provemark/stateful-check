<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-010 AC2 — a bounded float generator that shrinks toward an origin, modelled on integers()
 * (D038: the origin first, then binary-search halving toward it, stopping at a bounded step
 * count). The two generators are the ones the criterion names: an implicit origin that clamps to
 * 0.0 inside [-1.5, 1.5], and an explicit origin at the lower bound of [2.0, 8.0].
 *
 * The ordering after the origin is the greedy loop's retry-less-aggressively order, exactly as
 * integers(0, 9) shrinks 8 to [0, 4, 6, 7]: each candidate lies strictly between its predecessor
 * and the value, so it stays nearer the origin than that value while its own distance to the
 * origin grows.
 */

/** @return list<int> */
function ac2Seeds(): array
{
    return range(1, 20);
}

it('generates finite values inside its bounds', function () {
    $cases = [
        [Gen::floats(-1.5, 1.5), -1.5, 1.5],
        [Gen::floats(2.0, 8.0, origin: 2.0), 2.0, 8.0],
    ];

    foreach ($cases as [$g, $min, $max]) {
        foreach (ac2Seeds() as $seed) {
            $v = $g->generate(Source::seeded($seed))->value;

            // NAN and INF are the failure modes a float generator has and an integer one does not:
            // both would satisfy "within the bounds" vacuously, since every comparison with NAN is
            // false, so finiteness is asserted separately rather than folded into the range check.
            expect($v)->toBeFloat()
                ->and(is_finite($v))->toBeTrue()
                ->and($v)->toBeGreaterThanOrEqual($min)
                ->and($v)->toBeLessThanOrEqual($max);
        }
    }
})->group('SPEC-010');

it('offers the origin as its first shrink candidate, and nothing at the origin itself', function () {
    $g = Gen::floats(2.0, 8.0, origin: 2.0);

    $candidates = [...$g->shrink(new GeneratedValue(8.0))];

    expect($candidates)->not->toBeEmpty()
        ->and($candidates[0]->value)->toBe(2.0);

    // The origin is where shrinking stops: a value equal to it has no smaller alternative, which
    // is what makes the walk terminate rather than merely slow down.
    expect([...$g->shrink(new GeneratedValue(2.0))])->toBe([]);
})->group('SPEC-010');

it('shrinks with candidates strictly between their predecessor and the value, in bounded steps', function () {
    $cases = [
        [Gen::floats(-1.5, 1.5), 0.0],
        [Gen::floats(2.0, 8.0, origin: 2.0), 2.0],
    ];

    foreach ($cases as [$g, $origin]) {
        foreach (ac2Seeds() as $seed) {
            $v = $g->generate(Source::seeded($seed))->value;
            if (! is_float($v) || $v === $origin) {
                continue;
            }

            $candidates = [];
            foreach ($g->shrink(new GeneratedValue($v)) as $candidate) {
                $candidates[] = $candidate->value;
            }

            // Bounded: halving a float reaches zero only through the denormals, so termination is
            // a step cap and not a consequence of the arithmetic (unlike intdiv in integers()).
            expect(count($candidates))->toBeGreaterThan(0)
                ->and(count($candidates))->toBeLessThanOrEqual(64)
                ->and($candidates[0])->toBe($origin);

            $previous = $candidates[0];
            foreach (array_slice($candidates, 1) as $candidate) {
                expect($candidate)->toBeFloat();
                if (! is_float($candidate)) {
                    continue;
                }

                // Strictly between the predecessor and the value: both differences share a sign and
                // neither is zero, so the product is positive. That single check carries three
                // promises at once — the candidate is never the value it came from (the Generator
                // contract), never its own predecessor, and always on the origin's side of the value.
                expect(($candidate - $previous) * ($v - $candidate))->toBeGreaterThan(0.0)
                    ->and(abs($candidate - $origin))->toBeGreaterThan(abs($previous - $origin))
                    ->and(abs($candidate - $origin))->toBeLessThan(abs($v - $origin));

                $previous = $candidate;
            }
        }
    }
})->group('SPEC-010');

it('terminates when the first candidate is followed repeatedly', function () {
    $g = Gen::floats(-1.5, 1.5);

    foreach (ac2Seeds() as $seed) {
        $value = $g->generate(Source::seeded($seed))->value;

        // The "When" of AC2: always take the first candidate. Since that candidate is the origin,
        // the walk is two steps at most — the guard is here so a future ordering change cannot
        // quietly turn a shrink into a long or endless walk without failing a test.
        $steps = 0;
        while ($steps < 64) {
            $next = null;
            foreach ($g->shrink(new GeneratedValue($value)) as $candidate) {
                $next = $candidate->value;
                break;
            }

            if ($next === null) {
                break;
            }

            $value = $next;
            $steps++;
        }

        expect($steps)->toBeLessThan(64)
            ->and($value)->toBe(0.0);
    }
})->group('SPEC-010');

/**
 * SPEC-010 AC10 (floats) — invalid arguments throw at CONSTRUCTION, never at generation, where a
 * seeded run would fail halfway through and the seed would be blamed for the caller's mistake.
 * D015: an implicit origin is clamped into the range, an explicit one outside it throws.
 */
it('rejects an inverted range, a non-finite bound and an out-of-range explicit origin', function () {
    expect(fn () => Gen::floats(1.0, 0.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Gen::floats(NAN, 1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Gen::floats(0.0, INF))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Gen::floats(0.0, 1.0, origin: 5.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Gen::floats(0.0, 1.0, origin: NAN))->toThrow(InvalidArgumentException::class);

    // D016 in its float form: a range whose width is not representable. Every value drawn from it
    // would be INF or NAN, which AC2 forbids — so the guard belongs at construction with the rest.
    expect(fn () => Gen::floats(-PHP_FLOAT_MAX, PHP_FLOAT_MAX))->toThrow(InvalidArgumentException::class);
})->group('SPEC-010');

it('names the offending argument and its value in the message', function () {
    // A message that says only "invalid argument" sends the reader back to the source to find out
    // which one. Both the name and the value are in it, so the mistake is legible from the failure
    // alone — the same standard integers() already set.
    expect(fn () => Gen::floats(1.0, 0.0))
        ->toThrow(InvalidArgumentException::class, 'floats(): max (0) is below min (1).')
        ->and(fn () => Gen::floats(0.0, 1.0, origin: 5.0))
        ->toThrow(InvalidArgumentException::class, 'floats(): explicit origin (5) is outside [0, 1].');
})->group('SPEC-010');
