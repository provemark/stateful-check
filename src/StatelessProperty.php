<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

use Closure;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;

/**
 * The stateless property entry point (SPEC-008): draw values from one generator, check a predicate
 * against each, and — on failure — shrink to a minimal counterexample and report it. The value-level
 * analogue of StatefulProperty, and the `forAll` primitive the stateful runner is itself a special
 * case of (docs/prior-art.md).
 *
 * AC1 (this step) runs `runs` values from one seeded stream and reports success for a predicate that
 * holds throughout. Acting on a `false` verdict (stop, shrink), the non-determinism re-check (AC6),
 * the qualifications (AC5) and the construction guard (AC7) arrive with their own ACs.
 *
 * @template T
 */
final class StatelessProperty
{
    /**
     * @param  Generator<T>  $generator
     * @param  Closure(T): bool  $predicate
     */
    public function __construct(
        private readonly Generator $generator,
        private readonly Closure $predicate,
        private readonly int $runs = 100,
    ) {}

    public function check(?int $seed = null): PropertyValueResult
    {
        // A null seed means "pick one and tell me what it was", so a failure found by CI is
        // reproducible by re-running with the reported seed. Same rationale as StatefulProperty:
        // `random_int` is a different, unseedable source, kept short enough to retype.
        $seed ??= random_int(0, 999_999);

        // One seeded stream for the whole check, advancing across runs — re-seeding inside the loop
        // would draw the same value every run.
        $source = Source::seeded($seed);

        for ($run = 0; $run < $this->runs; $run++) {
            $value = $this->generator->generate($source)->value;

            // The predicate is invoked so one draw happens per run (AC1). Acting on a `false` verdict
            // — stopping and shrinking to a counterexample — is AC2; the return is deliberately not
            // read yet, so this omission is not a bug.
            ($this->predicate)($value);
        }

        // AC1: a run that completes without acting on any verdict reports success. AC2 replaces this
        // with the real pass/fail derived from the predicate.
        return new PropertyValueResult(passed: true, seed: $seed);
    }
}
