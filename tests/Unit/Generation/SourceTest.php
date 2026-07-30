<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-003 AC1 — the same seed reproduces the same values.
 */
it('produces an identical sequence from two sources seeded the same', function () {
    $a = Source::seeded(42);
    $b = Source::seeded(42);

    $seqA = array_map(fn () => $a->nextInt(0, 1_000_000), range(1, 20));
    $seqB = array_map(fn () => $b->nextInt(0, 1_000_000), range(1, 20));

    expect($seqA)->toBe($seqB);
})->group('SPEC-003');

/**
 * A regression guard for the engine mode — NOT a cross-process proof. A fixed value
 * within one run only shows the engine or mode has not silently shifted (for example
 * to MT_RAND_PHP, which yields a different stream for the same seed). Actual
 * cross-process reproducibility is verified separately in docs/verification/mt19937.php.
 *
 * The golden value comes from PHP's own Random extension seeded the same way, so it
 * is the reference the Source must reproduce.
 */
it('pins the Mt19937 engine mode against silent drift', function () {
    expect(Source::seeded(20260730)->nextInt(0, 1_000_000))->toBe(908515);
})->group('SPEC-003');
