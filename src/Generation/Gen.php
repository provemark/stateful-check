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
     * A bounded float generator that shrinks toward an origin (SPEC-010 AC2). The origin is offered
     * first and the remaining distance is then halved back toward the value, as integers() does;
     * an implicit origin clamps into the range (D015).
     *
     * @return Generator<float>
     */
    public static function floats(float $min, float $max, ?float $origin = null): Generator
    {
        return new FloatsGenerator($min, $max, $origin);
    }

    /**
     * A string of $minLength..$maxLength characters drawn from $alphabet (SPEC-010 AC3). Lengths
     * are counted in characters (code points), not bytes (D031). The alphabet is required: the
     * engine has no opinion about which characters are interesting, and a shipped default would be
     * a promise every recorded seed depends on (D035).
     *
     * $origin is the string shrinking moves toward. It defaults to $minLength repetitions of the
     * alphabet's first character; an explicit one lets a caller aim the shrink at a meaningful
     * value, such as a JSON Schema `default` (D032). Its characters must come from the alphabet
     * (D040), so every candidate stays a value this generator could itself have produced.
     *
     * @param  list<string>  $alphabet  single characters; shrinks toward the origin's characters
     * @return Generator<string>
     */
    public static function strings(int $minLength, int $maxLength, array $alphabet, ?string $origin = null): Generator
    {
        return new StringsGenerator($minLength, $maxLength, $alphabet, $origin);
    }

    /**
     * A bounded sequence of values from an item generator (SPEC-010 AC5) — the same shape as
     * strings(), whose characters are its items.
     *
     * @param  Generator<mixed>  $item
     * @return Generator<list<mixed>>
     */
    public static function listsOf(Generator $item, int $min, int $max): Generator
    {
        return new ListsGenerator($item, $min, $max);
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
     * Keys in $optional are present in some values and absent in others, and shrink to absent
     * before their value is shrunk (SPEC-010 AC7/AC8). A parameter rather than a second
     * combinator, so "a keyed record" stays one thing (D037).
     *
     * @param  array<array-key, Generator<mixed>>  $generators  always present
     * @param  array<array-key, Generator<mixed>>  $optional  sometimes present
     * @return Generator<array<array-key, mixed>>
     */
    public static function associative(array $generators, array $optional = []): Generator
    {
        return new AssociativeGenerator($generators, $optional);
    }

    /**
     * Uniform choice among branches of any type (SPEC-010 AC1) — the mechanism the command
     * alphabet already contained, without its Command typing. The generated value's context
     * records which branch was chosen, so shrinking delegates to the generator that produced the
     * value. The branch choice itself is not shrunk (the documented gap from SPEC-003 AC5).
     *
     * @param  list<Generator<mixed>>  $branches
     * @return Generator<mixed>
     */
    public static function oneOf(array $branches): Generator
    {
        return new OneOfGenerator($branches);
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
