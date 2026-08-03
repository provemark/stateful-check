<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;

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
