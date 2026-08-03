<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\StatelessProperty;

it('runs n values from a seeded stream and reports success for a passing property (SPEC-008 AC1)', function () {
    $seen = 0;
    $property = new StatelessProperty(
        generator: Gen::integers(0, 1_000_000),
        predicate: function (int $n) use (&$seen): bool {
            $seen++;

            return true;   // holds for every value
        },
        runs: 5,
    );

    $result = $property->check(seed: 999);

    // Only `passed` and `seed` exist on the result at AC1; the counterexample and qualification flags
    // arrive with AC2/AC5/AC6. That the predicate was called exactly `runs` times is the non-vacuous
    // proof the loop drew one value per run.
    expect($result->passed)->toBeTrue()
        ->and($result->seed)->toBe(999)
        ->and($seen)->toBe(5);
})->group('SPEC-008');

it('advances one seeded stream across the runs, so the drawn values are not all identical (SPEC-008 AC1)', function () {
    $received = [];
    $property = new StatelessProperty(
        generator: Gen::integers(0, 1_000_000),   // wide range, so a per-iteration re-seed would show up
        predicate: function (int $n) use (&$received): bool {
            $received[] = $n;

            return true;
        },
        runs: 5,
    );

    $property->check(seed: 999);

    // One `Source` runs through all runs. Re-seeding it per iteration would draw the SAME value five
    // times; that the drawn values are not all identical is the cheapest catch for a per-iteration
    // re-seed, which the call count alone cannot see (mirrors SPEC-005 AC1).
    expect($received)->toHaveCount(5)
        ->and(count(array_unique($received)))->toBeGreaterThan(1);
})->group('SPEC-008');

it('reports a failing value as a counterexample that still fails, before any shrinking (SPEC-008 AC2)', function () {
    $property = new StatelessProperty(
        generator: Gen::integers(0, 1_000_000),
        predicate: fn (int $n): bool => $n < 500_000,   // holds on the lower half, fails on the upper
        runs: 100,
    );

    $result = $property->check(seed: 12345);

    // The property does not hold, so the run fails and carries a counterexample. Part 1 does not shrink,
    // so the value is only required to *still fail* the predicate (R2 — never return a passing
    // counterexample); part 2 pins the exact minimum. `>= 500_000` is what "fails `n < 500_000`" means,
    // and it stays true after part 2 shrinks to the boundary 500_000, so this assertion is not weakened
    // later.
    expect($result->passed)->toBeFalse()
        ->and($result->counterexample)->not->toBeNull()
        ->and($result->counterexample)->toBeGreaterThanOrEqual(500_000);
})->group('SPEC-008');

it('shrinks the failing value toward the origin to the minimal value that still fails (SPEC-008 AC2)', function () {
    $property = new StatelessProperty(
        generator: Gen::integers(0, 1_000_000),
        predicate: fn (int $n): bool => $n < 500_000,
        runs: 100,
    );

    $result = $property->check(seed: 12345);

    // The raw failing draw at this seed is 666_698; the smallest value that still fails `n < 500_000`
    // is exactly the boundary 500_000 (R2/R3, verified against the real generator). Shrinking must
    // reduce the counterexample from the draw to that minimum.
    expect($result->passed)->toBeFalse()
        ->and($result->counterexample)->toBe(500_000);
})->group('SPEC-008');
