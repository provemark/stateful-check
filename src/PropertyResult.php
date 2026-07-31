<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The result of a property run (SPEC-005): whether it passed, and — when it did not — whether that
 * was a `vacuous` run rather than a counterexample (AC10). Grows field by field with its consumers:
 * the seed (AC4), the run count (AC1 sub-step 2), and on failure the counterexample, its `Failure`,
 * the drawn initial state, and the shrink qualifications (AC2/AC4/AC5), which also bring the types.
 *
 * `$vacuous` distinguishes the two kinds of `passed: false`: a real counterexample, or a run in which
 * no command ever executed so nothing was verified (AC10). The two are mutually exclusive — a failure
 * requires a command to have run.
 */
final readonly class PropertyResult
{
    public function __construct(
        public bool $passed,
        public bool $vacuous = false,
    ) {}
}
