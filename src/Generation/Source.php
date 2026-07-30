<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded, isolated, sequential source of randomness (PHP 8.2 Random extension).
 *
 * Per-instance state: two sources seeded the same produce the same stream, and
 * neither can interfere with the other — the determinism SPEC-003 AC1 rests on.
 */
final class Source
{
    public function __construct(private readonly Randomizer $randomizer) {}

    public static function seeded(int $seed): self
    {
        // The MT_RAND_MT19937 mode is pinned deliberately (AC1). On a PHP where the
        // default already equals it — as here — the tests pass without this argument,
        // so the pin guards against a future default change; it is not enforced by
        // the suite. MT_RAND_PHP would produce a different stream for the same seed.
        return new self(new Randomizer(new Mt19937($seed, MT_RAND_MT19937)));
    }

    public function nextInt(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }
}
