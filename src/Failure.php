<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * A structured description of why a run failed (SPEC-001 AC2).
 *
 * Structured, not a string, so SPEC-002 AC1 can ask "did the shrunk sequence fail for the
 * same reason" by comparing kind + commandClass + exceptionClass, which survive shrinking,
 * rather than the human `reason`, which legitimately changes. That comparison operation
 * arrives with its only consumer in SPEC-002; it is deliberately not built here.
 */
final readonly class Failure
{
    public function __construct(
        public FailureKind $kind,
        public int $index,
        public string $commandClass,
        // AC6 fills exceptionClass: the class of the exception run() threw.
        public ?string $exceptionClass = null,
        public ?string $reason = null,
    ) {}
}
