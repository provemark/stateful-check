<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The result of a stateless property check (SPEC-008, D027) — a separate value object, deliberately
 * not SPEC-005's PropertyResult reused: that carries a command list, a drawn initial state and a
 * runner Failure, none of which a single-value property has.
 *
 * It carries the verdict, the seed, on a failure the shrunk counterexample value, `$confirmedMinimum`
 * — whether that counterexample is a confirmed local minimum — and `$nonDeterministic`.
 *
 * `$confirmedMinimum` is `false` when the shrink stopped at its budget before reaching a local minimum
 * (AC5) OR the non-determinism guard fired (AC6): the counterexample is then not something the tool may
 * claim minimal (R3). `$nonDeterministic` distinguishes the two — `true` when re-checking the reported
 * counterexample flipped its verdict (AC6/D026), so the counterexample would not reproduce; `false` for
 * a budget-limited (but stable) counterexample. Both are only meaningful when there is a counterexample;
 * on a pass there is none, so they stay at their defaults rather than describing a value that does not
 * exist.
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
        public bool $confirmedMinimum = true,
        public bool $nonDeterministic = false,
    ) {}
}
