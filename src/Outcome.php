<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

use Throwable;

/**
 * The result of invoking a command: a returned value, or a thrown Throwable (SPEC-001).
 *
 * The runner wraps every `run()` invocation into an Outcome and hands it to the
 * postcondition, so a command that *expects* to throw asserts on this rather than the
 * run being treated as a crash (AC5).
 *
 * Not generic in the value. Since `postCondition` receives the outcome without the
 * command's `TResult` (D019), the value is always seen as `mixed`, and a command that
 * needs its result's type narrows it. A type parameter would buy nothing here and could
 * not be made covariant anyway — the `returned()` factory puts it in a parameter
 * position, which covariance forbids.
 */
final readonly class Outcome
{
    private function __construct(
        public bool $threw,
        public mixed $value = null,
        public ?Throwable $exception = null,
    ) {}

    public static function returned(mixed $value): self
    {
        return new self(false, $value);
    }

    public static function threw(Throwable $exception): self
    {
        return new self(true, null, $exception);
    }
}
