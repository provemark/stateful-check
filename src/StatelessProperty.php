<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

use Closure;
use InvalidArgumentException;
use Provemark\StatefulCheck\Generation\GeneratedValue;
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
        private readonly int $budget = 100,   // max shrink candidate executions (D007); its consumer is
        // PropertyValueResult::$confirmedMinimum (AC5)
    ) {
        // A run count below 1 is a static configuration under which the property verifies nothing, and
        // a property that ran nothing must never look like one that passed (AC7). Guard at construction,
        // so an invalid property never exists to be run (parallel to StatefulProperty AC6).
        if ($runs < 1) {
            throw new InvalidArgumentException(sprintf('StatelessProperty: runs must be at least 1, got %d.', $runs));
        }
    }

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
            $generated = $this->generator->generate($source);

            if (($this->predicate)($generated->value) === false) {
                // AC2: stop at the first failing value and shrink it toward the generator's origin to
                // the minimal value that still fails (R2/R3). AC5: if the shrink hits its budget first,
                // the counterexample is the best found so far, flagged not a confirmed minimum.
                [$counterexample, $confirmedMinimum] = $this->shrink($generated);

                return new PropertyValueResult(
                    passed: false,
                    seed: $seed,
                    counterexample: $counterexample->value,
                    confirmedMinimum: $confirmedMinimum,
                );
            }
        }

        // AC1: the predicate held for every drawn value, so the property passes.
        return new PropertyValueResult(passed: true, seed: $seed, counterexample: $this->noCounterexample());
    }

    /**
     * Greedily reduce a failing value to a local minimum that still fails: repeatedly take the first
     * shrink candidate whose value still fails the predicate and restart from it, until none does. It
     * terminates because every generator shrink candidate is strictly closer to the origin, so the
     * distance-to-origin measure falls on each accepted step (SPEC-003). R3: this is a *local* minimum
     * — no single further reduction still fails — never claimed as a global one. The shrink candidates
     * carry the generator's opaque context, so a composite value (elements, map, associative) reduces
     * correctly, not only a bare integer.
     *
     * AC5: each candidate the predicate is evaluated on counts against the budget (D007, a count of
     * executions, not time — a time budget would break determinism). When the budget is reached the
     * search stops and returns the best value found so far with `false` — not a confirmed minimum.
     * A natural stop (no candidate still fails) returns `true`.
     *
     * @param  GeneratedValue<T>  $failing
     * @return array{GeneratedValue<T>, bool} the counterexample and whether it is a confirmed minimum
     */
    private function shrink(GeneratedValue $failing): array
    {
        $current = $failing;
        $executions = 0;

        while (true) {
            $progressed = false;

            foreach ($this->generator->shrink($current) as $candidate) {
                if ($executions >= $this->budget) {
                    return [$current, false];
                }

                $executions++;

                if (($this->predicate)($candidate->value) === false) {
                    $current = $candidate;
                    $progressed = true;

                    break;
                }
            }

            if (! $progressed) {
                return [$current, true];
            }
        }
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
