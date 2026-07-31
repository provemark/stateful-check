<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

use InvalidArgumentException;
use Provemark\StatefulCheck\Generation\Generator;

/**
 * The property entry point (SPEC-005): the thing a user calls to generate command sequences from a
 * seed, run them, and on failure shrink and report a counterexample.
 *
 * So far only the construction guard exists (AC6). Generation, running, shrinking, `check()`, and
 * the `setup`/`initial` config it consumes all arrive with their own acceptance criteria — each
 * built with its consumer. The constructor holds only what the guard reads; storing `setup`/`initial`
 * now would be an unused parameter (or, promoted, a write-only property), which PHPStan max refuses
 * outright — the "build with its consumer" line enforced as a type error, not left to judgement.
 */
final class StatefulProperty
{
    /**
     * @param  list<Generator<Command<mixed, mixed, mixed>>>  $alphabet
     */
    public function __construct(
        array $alphabet,
        int $maxLength = 10,
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
}
