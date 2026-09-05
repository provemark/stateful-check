<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-003 AC3 — associative() generates a keyed record and shrinks one component at a
 * time, delegating each to its own generator. Two documented properties of that
 * strategy are tested rather than hidden: it is a local minimum for coupled keys (R3),
 * and "a candidate is never the input" is derived from the component generators.
 */
it('generates a record keyed by the generator keys', function () {
    $g = Gen::associative(['n' => Gen::integers(0, 5), 'label' => Gen::constant('x')]);

    $v = $g->generate(Source::seeded(3))->value;

    expect($v)->toHaveKeys(['n', 'label'])
        ->and($v['n'])->toBeInt()
        ->and($v['label'])->toBe('x');
})->group('SPEC-003');

it('shrinks one component at a time, never two together — the local minimum of R3', function () {
    // A bug requiring two coupled keys (e.g. n == m) cannot be reached by reducing
    // either alone, so the greedy loop stops at a non-minimal-looking record. That
    // limit is inherent in shrinking one component at a time; assert the mechanism.
    $g = Gen::associative(['n' => Gen::integers(0, 64), 'm' => Gen::integers(0, 64)]);
    $input = new GeneratedValue(
        ['n' => 8, 'm' => 8],
        ['n' => new GeneratedValue(8), 'm' => new GeneratedValue(8)],
    );

    foreach ($g->shrink($input) as $candidate) {
        $differing = 0;
        foreach (['n', 'm'] as $k) {
            if ($candidate->value[$k] !== $input->value[$k]) {
                $differing++;
            }
        }

        // Exactly one component changes per candidate — and so no candidate equals the
        // input record (that "never the input" guarantee is derived from the component
        // generators: integers by construction, elements via dedup, map via its filter).
        expect($differing)->toBe(1)
            ->and($candidate->value)->not->toBe($input->value);
    }
})->group('SPEC-003');

it('shrinks components in array order — the first key first (semantic, like elements)', function () {
    $g = Gen::associative(['n' => Gen::integers(0, 64), 'm' => Gen::integers(0, 64)]);
    $input = new GeneratedValue(
        ['n' => 8, 'm' => 8],
        ['n' => new GeneratedValue(8), 'm' => new GeneratedValue(8)],
    );

    $first = [...$g->shrink($input)][0];

    expect($first->value['n'])->not->toBe(8)
        ->and($first->value['m'])->toBe(8);
})->group('SPEC-003');

it('throws on a context of the wrong shape — a generator bug, not user input', function () {
    $g = Gen::associative(['n' => Gen::integers(0, 5)]);

    expect(fn () => [...$g->shrink(new GeneratedValue(['n' => 3], 'not-an-array-of-generated-values'))])
        ->toThrow(LogicException::class);
})->group('SPEC-003');

it('an empty record generates [] and does not shrink', function () {
    $g = Gen::associative([]);

    expect($g->generate(Source::seeded(1))->value)->toBe([])
        ->and([...$g->shrink(new GeneratedValue([], []))])->toBe([]);
})->group('SPEC-003');

/**
 * SPEC-010 AC7 — keys that may be absent are a second parameter on associative(), not a new
 * record() combinator (D037): it adds no new concept, leaves every existing call untouched, and
 * keeps "a keyed record" one thing in the user's head. Shrinking an optional key to absent is AC8.
 */
it('always produces its required keys and sometimes its optional ones', function () {
    $g = Gen::associative(['a' => Gen::integers(0, 9)], optional: ['b' => Gen::integers(0, 9)]);

    $present = [];
    foreach (range(1, 40) as $seed) {
        $value = $g->generate(Source::seeded($seed))->value;

        expect($value)->toBeArray();
        if (! is_array($value)) {
            continue;
        }

        // A required key is not optional in disguise: it is in every single value.
        expect($value)->toHaveKey('a');

        $present[] = array_key_exists('b', $value);
    }

    // Both shapes must occur, or the parameter generates nothing new: a key that is always there
    // is a required key, and one that never is could have been left out of the record.
    expect($present)->toContain(true)
        ->and($present)->toContain(false);
})->group('SPEC-010');

it('produces its keys in a deterministic order for a given seed', function () {
    $g = Gen::associative(['a' => Gen::integers(0, 9)], optional: ['b' => Gen::integers(0, 9)]);

    foreach (range(1, 40) as $seed) {
        $first = $g->generate(Source::seeded($seed))->value;
        $second = $g->generate(Source::seeded($seed))->value;

        // Identical values AND identical key order (=== compares order for arrays), in this
        // process and any other: nothing here may depend on hash order, spl_object_id or the
        // clock (R4). Required keys come first, in declaration order, then the optional ones.
        expect($second)->toBe($first);

        if (is_array($first)) {
            expect(array_keys($first))->toBe(array_key_exists('b', $first) ? ['a', 'b'] : ['a']);
        }
    }
})->group('SPEC-010');
