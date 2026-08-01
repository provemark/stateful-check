<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

use Closure;
use InvalidArgumentException;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;
use Provemark\StatefulCheck\Shrinking\SequenceShrinker;

/**
 * The property entry point (SPEC-005): the thing a user calls to generate command sequences from a
 * seed, run them, and on failure shrink and report a counterexample.
 *
 * It generates and runs `runs` sequences (AC1), stops at the first failure and shrinks it (AC2),
 * holds the drawn initial fixed while shrinking (AC8), and reports the four kinds of outcome —
 * a clean counterexample, a budget-limited or abandoned shrink (AC5), or a vacuous run (AC10).
 * Seed auto-generation and the full rendered report (AC4) arrive with their own tests.
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
        private readonly int $budget = 100,   // max shrink candidate executions (SPEC-002 D007); the
        // consumer of PropertyResult::$budgetExhausted (AC5)
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

    /**
     * @return PropertyResult<TModel, TSut, TInitial>
     */
    public function check(?int $seed = null): PropertyResult
    {
        // A null seed means "pick one and tell me what it was", so a failure found by CI is
        // reproducible by re-running with the reported seed. `random_int` is the cryptographically
        // secure generator — a *different* source than the package's own Mt19937, and unseedable by
        // design, which is exactly why it is right for choosing a seed. The range is kept to six digits
        // so the reported number is short enough for a human to retype out of a CI log: reproducibility
        // no one will retype is no reproducibility, and collisions are harmless here because each seed
        // reproduces its own run.
        $seed ??= random_int(0, 999_999);

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
            // Keep both forms: the bare command for this run, and the `GeneratedValue` wrapper (its
            // command plus generation context) that the shrinker needs to shrink arguments (SPEC-006).
            $commands = [];
            $wrapped = [];
            for ($position = 0; $position < $length; $position++) {
                $drawn = $commandGenerator->generate($source);
                $wrapped[] = $drawn;
                $commands[] = $drawn->value;
            }

            // Convert the setup once per sequence (AC7): a fresh model and system from a single
            // setup() call, so no sequence inherits another's state. `freshSut` is captured, so the
            // runner's one call returns this sequence's system.
            $initialValue = $this->initial->generate($source)->value;
            $setup = ($this->setup)($initialValue);
            $result = (new SequenceRunner)->run($commands, fn () => $setup->system, $setup->model);

            if (! $result->passed) {
                // AC2: the first failing sequence. Hand its run to the shrinker — the drawn sequence as
                // `GeneratedValue` wrappers (so each command's generation context survives for argument
                // shrinking, SPEC-006), the failing RunResult, a `freshSut` that rebuilds the system
                // **per candidate** (so no candidate inherits another's state, the R9b leak one layer
                // up), and the model — and report the shrunk counterexample. The Failure is the run's
                // own: the shrinker guarantees the shrunk sequence fails the same kind (D020), and it is
                // not re-run here.
                $shrunk = (new SequenceShrinker(new SequenceRunner, budget: $this->budget))->shrink(
                    $wrapped,
                    $result,
                    fn () => ($this->setup)($initialValue)->system,
                    $setup->model,
                );

                // Propagate the shrink's qualifications (AC5): a budget-limited or abandoned shrink must
                // be reported as such, so the counterexample is not presented as a confirmed minimum
                // when it is not (R3).
                return new PropertyResult(
                    passed: false,
                    seed: $seed,
                    counterexample: $shrunk->commands,
                    failure: $result->failure,
                    executions: $shrunk->executions,
                    budgetExhausted: $shrunk->budgetExhausted,
                    abandonedNonDeterministic: $shrunk->abandonedNonDeterministic,
                    initial: $initialValue,
                );
            }

            $anyExecuted = $anyExecuted || in_array(true, $result->executed, true);
        }

        if (! $anyExecuted) {
            // Every sequence passed, but not one command ever executed (all skipped by false
            // preconditions): the property verified nothing. A vacuous run must not look like a pass
            // (AC10, the runtime counterpart of AC6) — report failure, flagged as vacuous so it is not
            // mistaken for a counterexample.
            return new PropertyResult(passed: false, seed: $seed, vacuous: true, counterexample: $this->noCounterexample(), initial: $this->noInitial());
        }

        return new PropertyResult(passed: true, seed: $seed, counterexample: $this->noCounterexample(), initial: $this->noInitial());
    }

    /**
     * A typed empty counterexample for the pass and vacuous branches. PHPStan cannot infer TModel/TSut
     * from the constructor's bare `[]` default, so those branches would otherwise widen the result to
     * `PropertyResult<mixed, mixed>` and clash with `check()`'s return type. An empty list is a valid
     * `list<Command<TModel, TSut, mixed>>`, so this states the element type without an inline `@var`.
     *
     * @return list<Command<TModel, TSut, mixed>>
     */
    private function noCounterexample(): array
    {
        return [];
    }

    /**
     * A typed null initial for the pass and vacuous branches, where no single run's initial is the
     * answer. Like {@see noCounterexample()}, a bare `null` would widen the result's TInitial to
     * `mixed` and clash with `check()`'s return type; stating `TInitial|null` here binds it without an
     * inline `@var`. (The verify-first check for AC4 confirmed this helper is what the third template
     * needs — a bare `null` in these branches fails PHPStan max.)
     *
     * @return TInitial|null
     */
    private function noInitial(): mixed
    {
        return null;
    }
}
