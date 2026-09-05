<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-010 AC1 — `oneOf()` is the branch-choosing mechanism AlphabetGenerator already contains,
 * without its Command typing: the branch is selected by the source, the context records which
 * branch was chosen plus that branch's own GeneratedValue, and shrinking delegates to that branch
 * and never replaces it with another. The branch choice itself is not shrunk — the documented gap
 * inherited from SPEC-003 AC5, unchanged here.
 *
 * The branches are deliberately of *different* value types (int, string). That is what a command
 * alphabet never exercised — every branch there produces a Command — and it is the case the
 * consumer needs for `{"type":["string","null"]}`. `shrinkValuesOf()` is defined in
 * ElementsGeneratorTest.php.
 */

/**
 * @return Generator<mixed>
 */
function heterogeneousOneOf(): Generator
{
    return Gen::oneOf([
        Gen::integers(0, 9),
        Gen::elements(['a', 'b', 'c', 'd']),
    ]);
}

it('generates a value from a source-chosen branch, the same for the same seed', function () {
    $g = heterogeneousOneOf();

    $first = $g->generate(Source::seeded(7))->value;

    // A value of one branch or the other, and the same seed selects the same branch and value.
    expect($first === null || is_int($first) || is_string($first))->toBeTrue()
        ->and($g->generate(Source::seeded(7))->value)->toEqual($first);
})->group('SPEC-010');

it('records the chosen branch and that branch\'s own generated value in the context', function () {
    $gv = heterogeneousOneOf()->generate(Source::seeded(7));

    // The composite context is what makes delegation possible at all: without the index, shrink()
    // cannot know which branch produced the value; without the branch's own GeneratedValue, that
    // branch cannot shrink it (its context is opaque to everyone else).
    $context = $gv->context;

    expect($context)->toBeArray()
        ->and($context)->toHaveCount(2)
        ->and($context)->toHaveKeys([0, 1]);

    // The context is `mixed` by design — opaque to everyone but the generator that produced it —
    // so it is narrowed at runtime here exactly as the generators narrow it in shrink(). The
    // expectations above are what fail if the shape is wrong; this guard only keeps the analyser
    // honest about what has already been proven.
    if (! is_array($context) || ! array_key_exists(0, $context) || ! array_key_exists(1, $context)) {
        return;
    }

    [$index, $branchValue] = [$context[0], $context[1]];

    expect($index)->toBeInt()
        ->and($index)->toBeGreaterThanOrEqual(0)
        ->and($index)->toBeLessThanOrEqual(1)
        ->and($branchValue)->toBeInstanceOf(GeneratedValue::class);

    if ($branchValue instanceof GeneratedValue) {
        expect($branchValue->value)->toEqual($gv->value);
    }
})->group('SPEC-010');

it('shrinks through the recorded branch and never switches to another', function () {
    $g = heterogeneousOneOf();

    // A value as branch 1 (elements) produces 'd': its own context is the chosen index's
    // GeneratedValue, exactly as ElementsGenerator records it.
    $branchValue = new GeneratedValue('d', new GeneratedValue(3));
    $gv = new GeneratedValue('d', [1, $branchValue]);

    // Delegation to the *recorded* branch is the falsifiable part: index 3 shrinks like
    // integers(0, 3) toward 0, so the candidates are 'a' and 'c'. A generator that ignored the
    // index and used branch 0 would yield integers here.
    expect(shrinkValuesOf($g, $gv))->toBe(['a', 'c']);

    // And the branch is not replaced along the way: every candidate stays on branch 1, so a
    // further shrink of a candidate delegates to the same generator again.
    foreach ($g->shrink($gv) as $candidate) {
        $context = $candidate->context;

        expect($context)->toBeArray()
            ->and($context)->toHaveKey(0);

        if (is_array($context) && array_key_exists(0, $context)) {
            expect($context[0])->toBe(1);
        }
    }
})->group('SPEC-010');
