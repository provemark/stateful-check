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
