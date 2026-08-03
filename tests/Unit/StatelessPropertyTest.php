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

it('reproduces the same draws from the same seed, and varies with a different one (SPEC-008 AC3)', function () {
    $drawnWith = function (int $seed): array {
        $received = [];
        (new StatelessProperty(
            generator: Gen::integers(0, 1_000_000),
            predicate: function (int $n) use (&$received): bool {
                $received[] = $n;

                return true;   // holds, so all `runs` values are drawn
            },
            runs: 5,
        ))->check(seed: $seed);

        return $received;
    };

    // Same seed threads the same seeded stream through generation → identical draws. A different seed
    // varies them, so the seed genuinely reaches the generator rather than being ignored. The second
    // assertion is the mutation catch: a seed-ignoring but deterministic impl survives "same → same"
    // but not "different → different" (mirrors SPEC-005 AC3).
    expect($drawnWith(1))->toBe($drawnWith(1))
        ->and($drawnWith(1))->not->toBe($drawnWith(2));
})->group('SPEC-008');

it('reproduces the same counterexample from the same seed — the wiring stays deterministic (SPEC-008 AC3)', function () {
    $run = fn (): array => (function () {
        $result = (new StatelessProperty(
            generator: Gen::integers(0, 1_000_000),
            predicate: fn (int $n): bool => $n < 500_000,
            runs: 100,
        ))->check(seed: 777);

        return [$result->passed, $result->counterexample];
    })();

    // Two identical calls give an identical outcome — check() is a pure function of (config, seed),
    // with no hidden non-determinism in the draw/shrink pipeline. Note the shrunk counterexample is
    // seed-independent by design (it converges to the canonical minimum), so it is the *draws* test
    // above that catches a seed being ignored; this pins run-to-run stability of the whole result.
    expect($run())->toBe($run())
        ->and($run()[0])->toBeFalse();
})->group('SPEC-008');

it('throws at construction when the run count is below one (SPEC-008 AC7)', function () {
    // A run count below 1 would verify nothing, and a property that ran nothing must never look like
    // one that passed. Guard at construction, naming the offending value (parallel to SPEC-005 AC6).
    expect(fn () => new StatelessProperty(
        generator: Gen::integers(0, 10),
        predicate: fn (int $n): bool => true,
        runs: 0,
    ))->toThrow(InvalidArgumentException::class, 'got 0');
})->group('SPEC-008');

it('constructs without throwing when the configuration is valid (SPEC-008 AC7)', function () {
    $property = new StatelessProperty(
        generator: Gen::integers(0, 10),
        predicate: fn (int $n): bool => true,
        runs: 1,
    );

    expect($property)->toBeInstanceOf(StatelessProperty::class);
})->group('SPEC-008');

it('reports a budget-limited shrink as not a confirmed minimum (SPEC-008 AC5)', function () {
    $make = fn (int $budget): StatelessProperty => new StatelessProperty(
        generator: Gen::integers(0, 1_000_000),
        predicate: fn (int $n): bool => $n < 500_000,
        runs: 100,
        budget: $budget,
    );

    // The shrink to the minimum takes 93 candidate executions at this seed (measured). A budget of 100
    // covers it and confirms the minimum; a budget of 1 stops after the first candidate.
    $full = $make(100)->check(seed: 12345);
    $limited = $make(1)->check(seed: 12345);

    expect($full->confirmedMinimum)->toBeTrue()
        ->and($full->counterexample)->toBe(500_000)
        ->and($limited->passed)->toBeFalse()
        ->and($limited->confirmedMinimum)->toBeFalse()
        ->and($limited->counterexample)->toBeGreaterThan(500_000);   // R2: still fails, but not the minimum
})->group('SPEC-008');
