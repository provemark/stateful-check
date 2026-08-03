<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The result of a stateless property check (SPEC-008, D027) — a separate value object, deliberately
 * not SPEC-005's PropertyResult reused: that carries a command list, a drawn initial state and a
 * runner Failure, none of which a single-value property has.
 *
 * At AC1 it carries only the verdict and the seed. The shrunk counterexample, the
 * not-a-confirmed-minimum flag (AC5) and the non-determinism flag (AC6) arrive with their own ACs,
 * at which point it becomes generic in the value type (`@template T`).
 */
final readonly class PropertyValueResult
{
    public function __construct(
        public bool $passed,
        public int $seed,
    ) {}
}
