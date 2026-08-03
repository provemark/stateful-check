<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The result of a stateless property check (SPEC-008, D027) — a separate value object, deliberately
 * not SPEC-005's PropertyResult reused: that carries a command list, a drawn initial state and a
 * runner Failure, none of which a single-value property has.
 *
 * At AC2 part 1 it carries the verdict, the seed, and on a failure the counterexample value — the
 * failing value as drawn, not yet shrunk (part 2 shrinks it). The not-a-confirmed-minimum flag (AC5)
 * and the non-determinism flag (AC6) arrive with their own ACs.
 *
 * @template T
 */
final readonly class PropertyValueResult
{
    /**
     * @param  T|null  $counterexample  the failing value on a failure; null on a pass. Typed `mixed` on
     *                                  the property (mirroring PropertyResult) with the generic intent in
     *                                  the docblock, so a null pass-result does not have to bind T.
     */
    public function __construct(
        public bool $passed,
        public int $seed,
        public mixed $counterexample = null,
    ) {}
}
