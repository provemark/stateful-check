<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * Why a run failed. Decided by whether `run()` threw, not by the postcondition (the
 * precedence rule, SPEC-001 AC2): returned normally + postcondition false is
 * PostconditionFalse; threw + postcondition false is UnexpectedException.
 */
enum FailureKind
{
    case PostconditionFalse;

    // AC6 fills this: run() threw and the postcondition rejected that outcome.
    case UnexpectedException;
}
