<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * A mutable handle wrapping a system under test (SPEC-001 AC7).
 *
 * The runner threads one handle through the whole run and never replaces it. A command over an
 * immutable system swaps `$value` for the next immutable value, so the next command reads current
 * state from the handle rather than from a previous command's return (R9a). The runner never
 * touches `$value` — the handle only gives an immutable system an identity the runner can hold.
 *
 * @template T
 */
final class Ref
{
    /** @param  T  $value */
    public function __construct(public mixed $value) {}
}
