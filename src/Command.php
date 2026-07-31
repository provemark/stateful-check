<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * A single operation in a generated sequence (SPEC-001).
 *
 * Four responsibilities: a precondition, execution against the system under test, a
 * pure model transition, and a postcondition comparing system to model.
 *
 * Commands must be independent (R9a): no command may consume the result of an earlier
 * command. The shrinker shallow-clones every command before each candidate; a command
 * that holds a mutable object it must not share implements `__clone` to deep-copy it
 * (R9b, D006). A shallow clone copies scalar state but not held objects: a command that
 * keeps mutable state in a held object and does not implement `__clone` will share that
 * object across shrink candidates, so a mutation in one candidate leaks into the next —
 * the exact leak the clone prevents for scalar state. Its shrink candidates then interfere,
 * and the shrinker reports a wrong or unstable counterexample with no error. This cannot be
 * enforced (a user cannot be made to write `__clone`); it is the command author's contract.
 *
 * Three type parameters carry the model, the system handle, and the value `run()`
 * returns (D001). A user binds them once with `@implements Command<MyModel, MySut,
 * MyResult>`; PHPStan then types all four methods, so no per-method annotation is
 * needed. A user who wants none of it may bind `mixed`.
 *
 * TResult is covariant (D019): it appears only in `run()`'s return, so a
 * `Command<M, S, null>` is a `Command<M, S, mixed>`. That is what lets an alphabet mix
 * commands of different result types (dogfood example 2: `sign` returns null, `read`
 * returns a report). The price, as at D017, is that `postCondition` receives a
 * non-generic `Outcome` (value `mixed`) and narrows it itself if it needs the type.
 *
 * @template TModel
 * @template TSut
 *
 * @template-covariant TResult
 */
interface Command
{
    /**
     * May this run in the given model state? False means: skip, do not fail.
     *
     * @param  TModel  $model
     */
    public function preCondition(mixed $model): bool;

    /**
     * Execute against the system handle. May mutate it; must not replace it.
     *
     * @param  TSut  $sut
     * @return TResult
     */
    public function run(mixed $sut): mixed;

    /**
     * How the model changes as a result. MUST be pure, and MUST NOT depend on what
     * actually happened (R6).
     *
     * @param  TModel  $model
     * @return TModel
     */
    public function nextState(mixed $model): mixed;

    /**
     * $model is the state AFTER nextState. Does the system now match it? $sut is the same handle
     * run() operated on, reflecting every mutation up to and including this command (AC8), so a
     * postcondition may assert on system state, not only on the returned value. Read it, do not
     * mutate it: a convention (D011) the runner cannot enforce, not a guarantee — a postcondition
     * that mutates the system is not stopped, it just corrupts the run it is meant to check.
     *
     * The outcome is a non-generic `Outcome` (its value is `mixed`), not tied to
     * TResult, so that TResult stays covariant (D019); a command that needs its
     * result's type narrows the value.
     *
     * @param  TModel  $model
     * @param  TSut  $sut
     */
    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool;

    /** Used in counterexample output, e.g. "withdraw[3]". Keep it short. */
    public function __toString(): string;
}
