<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use Provemark\StatefulCheck\Command;

/**
 * Static facade over the generation combinators (SPEC-003).
 *
 * Only what the two dogfood suites need — `constant`, `elements`, `map`,
 * `associative` — built on `integers()`, the shrinking foundation they compose on.
 * The combinators arrive in later acceptance criteria; `integers()` is AC2.
 */
final class Gen
{
    /**
     * A bounded integer generator that shrinks toward an origin.
     *
     * @return Generator<int>
     */
    public static function integers(int $min, int $max, ?int $origin = null, int $edgeBias = 0): Generator
    {
        return new IntegersGenerator($min, $max, $origin, $edgeBias);
    }

    /**
     * A generator of one fixed value, with no shrinking.
     *
     * @template T
     *
     * @param  T  $value
     * @return Generator<T>
     */
    public static function constant(mixed $value): Generator
    {
        return new ConstantGenerator($value);
    }

    /**
     * A generator over a fixed set of values that shrinks toward the first element.
     *
     * @template T
     *
     * @param  array<array-key, T>  $choices
     * @return Generator<T>
     */
    public static function elements(array $choices): Generator
    {
        return new ElementsGenerator($choices);
    }

    /**
     * Applies a function to a generator's values; shrinking delegates to the inner
     * generator and re-applies the function.
     *
     * @template TIn
     * @template TOut
     *
     * @param  callable(TIn): TOut  $fn
     * @param  Generator<TIn>  $inner
     * @return Generator<TOut>
     */
    public static function map(callable $fn, Generator $inner): Generator
    {
        return new MapGenerator($fn, $inner);
    }

    /**
     * A generator of a keyed record; shrinking reduces one component at a time, in
     * array order. The generators may be heterogeneous (Generator is covariant, D017).
     *
     * @param  array<array-key, Generator<mixed>>  $generators
     * @return Generator<array<array-key, mixed>>
     */
    public static function associative(array $generators): Generator
    {
        return new AssociativeGenerator($generators);
    }

    /**
     * Uniform choice over a command alphabet (SPEC-003 AC5). The generated value's context records
     * which branch was chosen, so shrinking delegates the command's argument-shrinking to the
     * generator that produced it. The branch choice itself is not shrunk (a documented coverage
     * gap). Result types may differ across branches — Command's TResult is covariant (D019).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<Generator<Command<TModel, TSut, mixed>>>  $branches
     * @return Generator<Command<TModel, TSut, mixed>>
     */
    public static function alphabet(array $branches): Generator
    {
        return new AlphabetGenerator($branches);
    }
}
