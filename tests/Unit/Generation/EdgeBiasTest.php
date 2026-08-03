<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;
use Provemark\StatefulCheck\StatelessProperty;

it('draws boundary values under edge bias, which uniform generation does not (SPEC-009 AC1)', function () {
    $draw = function (Generator $g): array {
        $source = Source::seeded(42);
        $out = [];
        for ($i = 0; $i < 100; $i++) {
            $out[] = $g->generate($source)->value;
        }

        return $out;
    };

    // origin(=min) and max of this range
    $edgeCount = fn (array $draws): int => count(array_filter(
        $draws,
        fn ($v): bool => in_array($v, [0, 1_000_000], true),
    ));

    $biased = $draw(Gen::integers(0, 1_000_000, edgeBias: 20));
    $uniform = $draw(Gen::integers(0, 1_000_000));   // edgeBias defaults to 0 — today's pure uniform

    // Bias produces edge values; uniform over a million-wide range essentially never does. Deterministic
    // at the fixed seed, so this is a pinned fact, not a statistical one.
    expect($edgeCount($biased))->toBeGreaterThan(0)
        ->and($edgeCount($uniform))->toBe(0);
})->group('SPEC-009');

it('reproduces the identical biased sequence from the same seed, and varies with a different one (SPEC-009 AC2)', function () {
    $draw = function (int $seed): array {
        $g = Gen::integers(0, 1_000_000, edgeBias: 20);
        $source = Source::seeded($seed);
        $out = [];
        for ($i = 0; $i < 100; $i++) {
            $out[] = $g->generate($source)->value;
        }

        return $out;
    };

    // Same seed → the identical sequence, edge draws included: the bias decision and the edge index both
    // come from the seeded Source, so nothing non-deterministic enters (R4). A different seed varies it,
    // which is the catch that the seed genuinely reaches the biased draw rather than being ignored.
    expect($draw(7))->toBe($draw(7))
        ->and($draw(7))->not->toBe($draw(1));
})->group('SPEC-009');

it('shrinks an edge-drawn counterexample toward the origin like any other value (SPEC-009 AC3)', function () {
    // edgeBias 100: every draw is a boundary value (0 or 1_000_000). The predicate passes at 0 and fails
    // at the max edge, so the counterexample is drawn from the edge-set — yet it must shrink to the
    // boundary minimum 500_000 exactly as a uniform-drawn value would. shrink() reads the value, not how
    // it was drawn (integer context is null either way).
    $result = (new StatelessProperty(
        generator: Gen::integers(0, 1_000_000, edgeBias: 100),
        predicate: fn (int $n): bool => $n < 500_000,
        runs: 100,
    ))->check(seed: 1);

    expect($result->passed)->toBeFalse()
        ->and($result->counterexample)->toBe(500_000);
})->group('SPEC-009');
