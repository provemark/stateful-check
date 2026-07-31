<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * The setup for one sequence execution (SPEC-005 AC7, D012): a fresh model and a fresh system, built
 * together from one `setup($initial)` call so they start from the same initial state. The entry point
 * derives `initialModel = $model` and `freshSut = fn () => $system` from a single instance.
 *
 * @template TModel
 * @template TSut
 */
final readonly class Setup
{
    /**
     * @param  TModel  $model
     * @param  TSut  $system
     */
    public function __construct(
        public mixed $model,
        public mixed $system,
    ) {}
}
