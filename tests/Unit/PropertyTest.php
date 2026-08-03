<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Property;

it('runs n values from a seeded stream and reports success for a passing property (SPEC-008 AC1)', function () {
    $seen = 0;
    $property = new Property(
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
    $property = new Property(
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
