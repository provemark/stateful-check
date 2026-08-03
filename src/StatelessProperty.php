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
 * It runs `runs` values from one seeded stream, reports success for a predicate that holds throughout
 * (AC1), and stops at the first value that fails, reporting it as the counterexample (AC2 part 1).
 * Shrinking that value to a minimum (AC2 part 2), the non-determinism re-check (AC6), the
 * qualifications (AC5) and the construction guard (AC7) arrive with their own ACs.
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

    /**
     * @return PropertyValueResult<T>
     */
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

            if (($this->predicate)($value) === false) {
                // AC2 part 1: stop at the first failing value and report it as the counterexample, as
                // drawn. Part 2 will shrink it toward the origin to the minimal value that still fails
                // before returning; here it is reported raw.
                return new PropertyValueResult(passed: false, seed: $seed, counterexample: $value);
            }
        }

        // AC1: the predicate held for every drawn value, so the property passes.
        return new PropertyValueResult(passed: true, seed: $seed, counterexample: $this->noCounterexample());
    }

    /**
     * A typed null counterexample for the pass branch, where there is no failing value. Mirrors
     * StatefulProperty::noInitial(): returning a bare `null` would bind the result's `T` to `null`
     * instead of the property's value type, so the helper carries the generic intent.
     *
     * @return T|null
     */
    private function noCounterexample(): mixed
    {
        return null;
    }
}
