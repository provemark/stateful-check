<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The outcome of running a command sequence (SPEC-001).
 *
 * Minimal for AC1: pass/fail, and which commands executed (the execution path SPEC-002
 * shrinks over, AC4). The structured `Failure` and the model snapshots arrive with AC2.
 */
final readonly class RunResult
{
    /**
     * @param  list<bool>  $executed  per position: did this command run?
     */
    public function __construct(
        public bool $passed,
        public array $executed,
    ) {}
}
