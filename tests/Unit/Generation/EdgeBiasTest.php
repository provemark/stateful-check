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

it('propagates edge bias through map and associative by delegation (SPEC-009 AC4)', function () {
    $draw = function (Generator $g): array {
        $source = Source::seeded(42);
        $out = [];
        for ($i = 0; $i < 100; $i++) {
            $out[] = $g->generate($source)->value;
        }

        return $out;
    };

    // map over a biased integer: the inner edges (0, 1_000_000) survive the +1 transform as (1, 1_000_001);
    // over a uniform integer they do not. So the bias reaches through map, with no change to map itself.
    $inc = fn (int $n): int => $n + 1;
    $mappedBiased = $draw(Gen::map($inc, Gen::integers(0, 1_000_000, edgeBias: 20)));
    $mappedUniform = $draw(Gen::map($inc, Gen::integers(0, 1_000_000)));
    $mapEdges = fn (array $xs): int => count(array_filter($xs, fn ($v): bool => in_array($v, [1, 1_000_001], true)));

    // associative over a biased integer: the record's key carries the inner edge values.
    $records = $draw(Gen::associative(['n' => Gen::integers(0, 1_000_000, edgeBias: 20)]));
    $recordEdges = 0;
    foreach ($records as $r) {
        if (is_array($r) && in_array($r['n'] ?? null, [0, 1_000_000], true)) {
            $recordEdges++;
        }
    }

    expect($mapEdges($mappedBiased))->toBeGreaterThan(0)
        ->and($mapEdges($mappedUniform))->toBe(0)
        ->and($recordEdges)->toBeGreaterThan(0);
})->group('SPEC-009');

it('rejects an edgeBias outside 0..100 at construction (SPEC-009 AC6)', function () {
    // A percentage above 100 or below 0 is a caller error; guard at construction, naming the value.
    expect(fn () => Gen::integers(0, 10, edgeBias: 101))
        ->toThrow(InvalidArgumentException::class, '101');
    expect(fn () => Gen::integers(0, 10, edgeBias: -1))
        ->toThrow(InvalidArgumentException::class, '-1');

    // The boundaries are valid: 0 is off (the default), 100 is always-edge.
    expect(fn () => Gen::integers(0, 10, edgeBias: 0))->not->toThrow(InvalidArgumentException::class);
    expect(fn () => Gen::integers(0, 10, edgeBias: 100))->not->toThrow(InvalidArgumentException::class);
})->group('SPEC-009');
