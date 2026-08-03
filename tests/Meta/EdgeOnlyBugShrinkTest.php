<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\StatelessProperty;

/**
 * A property with a planted edge-only bug: it holds for every value except the maximum, where it fails.
 * Uniform generation over a million-wide range essentially never draws the max, so it reports a false
 * pass; edge-biasing draws the boundary and finds the bug. This is the criterion that proves edge-bias
 * earns its place (R8) — not that generation "is biased", but that it catches an edge bug uniform misses.
 *
 * The 10% frequency is the measured recommendation (D030): the smallest that found this bug across all
 * 40 measured seeds within a 100-run budget (uniform 0/40, 5% 36/40, 8% 37/40, 10% 40/40).
 */
$max = 1_000_000;
$planted = fn (int $n): bool => $n !== $max;   // holds everywhere except the max edge

it('edge bias finds an edge-only bug that uniform generation misses (SPEC-009 AC5)', function () use ($max, $planted) {
    $run = fn (int $edgeBias) => (new StatelessProperty(
        generator: Gen::integers(0, $max, edgeBias: $edgeBias),
        predicate: $planted,
        runs: 100,
    ))->check(seed: 7);

    $uniform = $run(0);    // pure uniform — the false pass
    $biased = $run(10);    // the measured frequency (D030)

    // Uniform misses the edge bug (a false pass); edge-bias finds it, and the counterexample is the max
    // itself — the only failing value, already its own minimum, so shrinking leaves it there.
    expect($uniform->passed)->toBeTrue()
        ->and($biased->passed)->toBeFalse()
        ->and($biased->counterexample)->toBe($max);
})->group('meta')->group('SPEC-009');
