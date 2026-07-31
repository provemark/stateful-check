<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The result of a property run (SPEC-005): whether it passed, and on a failure the shrunk
 * counterexample, its `Failure`, how many candidate executions the shrink took (AC2), and the shrink
 * qualifications (AC5), and the seed the run was generated from (AC4). Grows field by field with its
 * consumers: the drawn initial state (AC4) is still to come, and brings `TInitial` with it.
 *
 * **`passed: false` has exactly four kinds, and they are mutually exclusive — one flag true, or none
 * for a clean counterexample.** A reader must be able to tell them apart, and the exclusion is a
 * property to state, not a happy accident of how the branches are written:
 *
 * - `$vacuous` — no command ever executed, so nothing was verified (AC10). It excludes the other three
 *   because a vacuous run has **no `Failure`**: nothing ran, so nothing failed.
 * - `$abandonedNonDeterministic` — the shrinker replayed the failing sequence, found the system
 *   unstable, and gave up (SPEC-002 AC8). `$counterexample` is then the **original, unshrunk** sequence.
 * - `$budgetExhausted` — the shrink stopped at its budget before confirming a local minimum (AC9).
 *   `$counterexample` is the best found so far.
 * - none of the three — a clean, confirmed local-minimum counterexample.
 *
 * `$abandonedNonDeterministic` and `$budgetExhausted` exclude each other because the abort happens
 * **before** the candidate loop (`$executions === 0`) while a budget stop happens **during** it
 * (`$executions > 0`). Revisit this exclusion if the shrinker is ever changed to abort *inside* the
 * loop, or if a budget-limited run could also be reported non-deterministic.
 *
 * **R3, the property this protects:** `$budgetExhausted` or `$abandonedNonDeterministic` both mean the
 * counterexample is **not a confirmed local minimum** — the tool may claim no more than it verified.
 * A caller may only trust the counterexample as minimal when neither is set. This question is only
 * meaningful **when there is a counterexample**: for a vacuous run there is none, so minimality is not
 * false but undefined — do not read "vacuous" as "minimal".
 *
 * @template TModel
 * @template TSut
 * @template TInitial
 */
final readonly class PropertyResult
{
    /**
     * @param  list<Command<TModel, TSut, mixed>>  $counterexample  the counterexample on a failure
     *                                                              (shrunk, best-so-far, or unshrunk per the
     *                                                              flags above); empty on a pass or a vacuous run
     * @param  TInitial|null  $initial  the drawn initial state that produced the counterexample; null on a
     *                                  pass or a vacuous run, where no single run's initial is the answer
     */
    public function __construct(
        public bool $passed,
        public int $seed,
        public bool $vacuous = false,
        public array $counterexample = [],
        public ?Failure $failure = null,
        public int $executions = 0,
        public bool $budgetExhausted = false,
        public bool $abandonedNonDeterministic = false,
        public mixed $initial = null,
    ) {}
}
