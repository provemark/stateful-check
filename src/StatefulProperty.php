<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

use Closure;
use InvalidArgumentException;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;

/**
 * The property entry point (SPEC-005): the thing a user calls to generate command sequences from a
 * seed, run them, and on failure shrink and report a counterexample.
 *
 * So far it runs a single passing sequence (AC1 sub-step 1). The run count (sub-step 2), the
 * length/vacuous-pass guarantees (sub-step 3), the failure path (AC2), and seed auto-generation +
 * reporting (AC4) arrive with their own tests.
 *
 * @template TModel
 * @template TSut
 * @template TInitial
 */
final class StatefulProperty
{
    /**
     * @param  list<Generator<Command<TModel, TSut, mixed>>>  $alphabet
     * @param  Closure(TInitial): Setup<TModel, TSut>  $setup
     * @param  Generator<TInitial>  $initial
     */
    public function __construct(
        private readonly array $alphabet,
        private readonly Closure $setup,
        private readonly Generator $initial,
        private readonly int $maxLength = 10,
        private readonly int $runs = 100,
    ) {
        // Each of these three is a static configuration under which the property would execute
        // nothing, and a property that ran nothing must never look like one that passed (AC6). Guard
        // at construction, so an invalid property never exists to be run.
        if ($alphabet === []) {
            throw new InvalidArgumentException('StatefulProperty: the command alphabet must not be empty — a property with no commands verifies nothing.');
        }

        if ($maxLength < 1) {
            throw new InvalidArgumentException(sprintf('StatefulProperty: maxLength must be at least 1, got %d.', $maxLength));
        }

        if ($runs < 1) {
            throw new InvalidArgumentException(sprintf('StatefulProperty: runs must be at least 1, got %d.', $runs));
        }
    }

    public function check(int $seed): PropertyResult
    {
        // One seeded stream for the whole check: it advances across sequences, so each run draws a
        // different sequence and initial. Re-seeding inside the loop would draw the same sequence n
        // times.
        $source = Source::seeded($seed);
        // `min: 1` is load-bearing (AC9: never an empty sequence). `origin: 1` has no consumer yet —
        // the drawn length is never shrunk through this generator (SPEC-002 shrinks the command list
        // structurally); it is the right value if length-shrinking is ever added.
        $lengths = Gen::integers(1, $this->maxLength, origin: 1);
        $commandGenerator = Gen::alphabet($this->alphabet);

        // Whether any command executed across all runs (AC10). Read from the runner's own `executed`
        // record — nothing new is counted.
        $anyExecuted = false;

        for ($run = 0; $run < $this->runs; $run++) {
            // Draw one sequence: a length in [1, maxLength] (origin 1), then that many commands drawn
            // uniformly from the alphabet.
            $length = $lengths->generate($source)->value;
            $commands = [];
            for ($position = 0; $position < $length; $position++) {
                $commands[] = $commandGenerator->generate($source)->value;
            }

            // Convert the setup once per sequence (AC7): a fresh model and system from a single
            // setup() call, so no sequence inherits another's state. `freshSut` is captured, so the
            // runner's one call returns this sequence's system.
            $initialValue = $this->initial->generate($source)->value;
            $setup = ($this->setup)($initialValue);
            $result = (new SequenceRunner)->run($commands, fn () => $setup->system, $setup->model);

            if (! $result->passed) {
                // Stop at the first failing sequence. AC2 will enrich this with the shrunk
                // counterexample and its Failure; for now it is a bare failure verdict.
                return new PropertyResult(passed: false);
            }

            $anyExecuted = $anyExecuted || in_array(true, $result->executed, true);
        }

        if (! $anyExecuted) {
            // Every sequence passed, but not one command ever executed (all skipped by false
            // preconditions): the property verified nothing. A vacuous run must not look like a pass
            // (AC10, the runtime counterpart of AC6) — report failure, flagged as vacuous so it is not
            // mistaken for a counterexample.
            return new PropertyResult(passed: false, vacuous: true);
        }

        return new PropertyResult(passed: true);
    }
}
