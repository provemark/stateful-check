<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The result of a property run (SPEC-005): so far only whether it passed. Grows field by field with
 * its consumers — the seed (AC4), the run count (AC1 sub-step 2), and on failure the counterexample,
 * its `Failure`, the drawn initial state, and the shrink qualifications (AC2/AC4/AC5), which also
 * bring the type parameters.
 */
final readonly class PropertyResult
{
    public function __construct(
        public bool $passed,
    ) {}
}
