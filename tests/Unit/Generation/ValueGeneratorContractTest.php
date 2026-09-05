<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-010 AC11 — every generator this spec adds satisfies the Generator contract, asserted from
 * one table rather than left to each generator's own tests to cover by accident.
 *
 * Three promises: a candidate never equals the value it was shrunk from (on which the greedy
 * loop's termination rests), a shrink sequence is finite, and every candidate carries a context
 * that permits further shrinking — a candidate that cannot itself be shrunk would end the
 * reduction one step in, with no signal.
 *
 * @return array<string, Generator<mixed>>
 */
function ac11Generators(): array
{
    return [
        'oneOf' => Gen::oneOf([Gen::integers(0, 9), Gen::elements(['a', 'b', 'c'])]),
        'floats' => Gen::floats(-1.5, 1.5),
        'strings' => Gen::strings(0, 5, ['a', 'b', 'c']),
        'listsOf' => Gen::listsOf(Gen::integers(0, 20), 1, 4),
        'subsetOf' => Gen::subsetOf(['a', 'b', 'c'], 1, 3),
        'associative' => Gen::associative(['a' => Gen::integers(0, 9)], optional: ['b' => Gen::integers(0, 9)]),
    ];
}

it('never offers a candidate equal to the value it was shrunk from, and always terminates', function () {
    foreach (ac11Generators() as $name => $g) {
        foreach (range(1, 10) as $seed) {
            $value = $g->generate(Source::seeded($seed));

            $steps = 0;
            while ($steps < 64) {
                $candidates = [...$g->shrink($value)];

                // Finite, and bounded well below anything a report could carry.
                expect(count($candidates))->toBeLessThanOrEqual(512, $name);

                foreach ($candidates as $candidate) {
                    expect($candidate->value)->not->toEqual($value->value, $name);
                }

                if ($candidates === []) {
                    break;
                }

                $value = $candidates[0];
                $steps++;
            }

            // The walk ended because there was nothing left to offer, not because the bound cut it
            // off: an unbounded sequence would spin here rather than fail visibly.
            expect($steps)->toBeLessThan(64, $name);
        }
    }
})->group('SPEC-010');

it('gives every candidate a context that carries the reduction through to the minimum', function () {
    // Each generator's true minimum: what the greedy walk must arrive at. Reaching it is the
    // discriminating test for "a context that permits further shrinking" — a candidate whose
    // context was dropped or emptied still shrinks, it just shrinks LESS, and stops early at
    // something that is not the minimum. Enumerating a candidate's candidates and checking only
    // that nothing throws does not catch that, which a mutant proved: emptying the recorded items
    // of a list candidate left such a check entirely green.
    $minima = [
        'floats' => 0.0,
        'strings' => '',
        'listsOf' => [0],
        'subsetOf' => ['a'],
        'associative' => ['a' => 0],
    ];

    foreach (ac11Generators() as $name => $g) {
        foreach (range(1, 10) as $seed) {
            $value = $g->generate(Source::seeded($seed));

            $steps = 0;
            while ($steps < 64) {
                $next = null;
                foreach ($g->shrink($value) as $candidate) {
                    // A context that is malformed rather than merely poorer throws here, which is
                    // the loud failure the generators produce instead of yielding nothing.
                    $next ??= $candidate;
                }

                if ($next === null) {
                    break;
                }

                $value = $next;
                $steps++;
            }

            if ($name === 'oneOf') {
                // Whichever branch was chosen: integers' origin or elements' first choice. The
                // branch itself is never shrunk — the documented gap from SPEC-003 AC5.
                expect([0, 'a'])->toContain($value->value);

                continue;
            }

            expect($value->value)->toBe($minima[$name], $name);
        }
    }
})->group('SPEC-010');

it('throws on a context of the wrong shape — a generator bug, not user input', function () {
    $wrong = [
        'oneOf' => new GeneratedValue(3, 'not a branch record'),
        'strings' => new GeneratedValue('abc', 'not a length'),
        'listsOf' => new GeneratedValue([1, 2], 'not a length and items'),
        'subsetOf' => new GeneratedValue(['a'], 'not a size and indices'),
        'associative' => new GeneratedValue(['a' => 1], 'not a keyed context'),

        // floats() carries a null context by design — its value is self-describing — so the shape
        // that can be wrong is the value itself.
        'floats' => new GeneratedValue('not a float'),
    ];

    $generators = ac11Generators();

    foreach ($wrong as $name => $value) {
        expect(fn () => [...$generators[$name]->shrink($value)])
            ->toThrow(LogicException::class, '', $name);
    }
})->group('SPEC-010');
