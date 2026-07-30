<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The outcome of running a command sequence (SPEC-001).
 *
 * Carries pass/fail, which commands executed (the execution path SPEC-002 shrinks over,
 * AC4), and — on failure — a structured `Failure` plus the model on either side of the
 * failing transition.
 *
 * A false in `executed` has two causes this type deliberately does not distinguish: the
 * command was skipped by a false precondition (AC3), or it was never reached because the run
 * stopped at an earlier failure (AC2). SPEC-002 does not need the difference — both fall out
 * of the shrink representation identically — but a diagnostic reader cannot tell them apart,
 * so the merge is stated here rather than left to be assumed away.
 *
 * `modelBefore` and `modelAfter` are failure diagnostics: they pin the transition at the
 * failing command (`modelBefore` is the model entering it, `modelAfter` the model after its
 * `nextState`). On a passing run there is no distinguished transition to point at — there
 * are as many as commands — so both stay null. Fill them only when reporting a failure.
 */
final readonly class RunResult
{
    /**
     * @param  list<bool>  $executed  per position: did this command run?
     */
    public function __construct(
        public bool $passed,
        public array $executed,
        public ?Failure $failure = null,
        public mixed $modelBefore = null,
        public mixed $modelAfter = null,
    ) {}
}
