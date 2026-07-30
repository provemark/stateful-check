<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

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
    public static function integers(int $min, int $max, ?int $origin = null): Generator
    {
        return new IntegersGenerator($min, $max, $origin);
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
}
