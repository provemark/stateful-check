<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The result of a property run (SPEC-005): whether it passed, and on a failure the shrunk
 * counterexample, its `Failure`, and how many candidate executions the shrink took (AC2). Grows field
 * by field with its consumers: the seed (AC4), the drawn initial state and shrink qualifications
 * (AC4/AC5), which also bring `TInitial`.
 *
 * `$vacuous` distinguishes the two kinds of `passed: false`: a real counterexample, or a run in which
 * no command ever executed so nothing was verified (AC10). The two are mutually exclusive — a failure
 * requires a command to have run.
 *
 * @template TModel
 * @template TSut
 */
final readonly class PropertyResult
{
    /**
     * @param  list<Command<TModel, TSut, mixed>>  $counterexample  the shrunk counterexample on a
     *                                                              failure; empty on a pass or a vacuous run
     */
    public function __construct(
        public bool $passed,
        public bool $vacuous = false,
        public array $counterexample = [],
        public ?Failure $failure = null,
        public int $executions = 0,
    ) {}
}
