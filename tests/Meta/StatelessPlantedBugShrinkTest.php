<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\StatelessProperty;

/**
 * A function under test with a planted bug: it is meant to hold for every value in range, but a planted
 * threshold makes it fail at n >= 50. The property must not merely find *a* failure (the raw draw) — it
 * must shrink to the *exact* smallest triggering value, 50 (R8). A shrinker that merely does not crash is
 * not tested; this pins the minimum. It is the stateless analogue of the stateful planted-bug meta-tests
 * (e.g. OrderDependentShrinkTest), and of SPEC-006 AC7's `amount(50)` for argument shrinking.
 */
$buggy = fn (int $n): bool => $n < 50;

it('shrinks a planted stateless bug to its exact minimal counterexample (SPEC-008 AC8)', function () use ($buggy) {
    $result = (new StatelessProperty(
        generator: Gen::integers(0, 1000),
        predicate: $buggy,
        runs: 100,
    ))->check(seed: 42);

    expect($result->passed)->toBeFalse()
        ->and($result->counterexample)->toBe(50)          // the exact minimum (R8), not merely a failure
        ->and($result->confirmedMinimum)->toBeTrue();     // reached within budget: a clean local minimum
})->group('meta')->group('SPEC-008');
