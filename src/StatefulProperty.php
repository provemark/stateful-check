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
        int $runs = 100,
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
        $source = Source::seeded($seed);

        // Draw one sequence: a length in [1, maxLength] (origin 1), then that many commands drawn
        // uniformly from the alphabet.
        $length = Gen::integers(1, $this->maxLength, origin: 1)->generate($source)->value;
        $commandGenerator = Gen::alphabet($this->alphabet);
        $commands = [];
        for ($i = 0; $i < $length; $i++) {
            $commands[] = $commandGenerator->generate($source)->value;
        }

        // Convert the setup once (AC7): draw the initial value, build model and system from a single
        // setup() call, and run. `freshSut` is captured, so the runner's one call returns this system.
        $initialValue = $this->initial->generate($source)->value;
        $setup = ($this->setup)($initialValue);
        $run = (new SequenceRunner)->run($commands, fn () => $setup->system, $setup->model);

        return new PropertyResult(passed: $run->passed);
    }
}
