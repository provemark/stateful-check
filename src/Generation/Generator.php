<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

/**
 * A generator of values of type T, with value-level shrinking (SPEC-003).
 *
 * shrink() is a method on the generator, not on the value: the generator holds its
 * own config (bounds, origin, sub-generators) and reads only the opaque context of
 * the GeneratedValue handed back to it. Candidates come closest-to-origin first and
 * the sequence is finite (AC2 for integers; combinators delegate, AC3).
 *
 * Covariant in T: T appears only in output positions (generate's return, shrink's
 * return). shrink() therefore takes a `GeneratedValue<mixed>`, not `GeneratedValue<T>`
 * — an implementation narrows what it receives at runtime and throws a LogicException
 * on the wrong shape (the pattern used by elements, map and associative). Covariance is
 * what lets a `Generator<int>` be passed where a `Generator<mixed>` is expected, so a
 * heterogeneous set of generators (associative's keyed record) type-checks. The cost is
 * that static "you get the right value" is traded for that runtime narrowing (D017).
 *
 * @template-covariant T
 */
interface Generator
{
    /**
     * @return GeneratedValue<T>
     */
    public function generate(Source $source): GeneratedValue;

    /**
     * Smaller alternatives, closest-to-origin first, finite. Never yields $value
     * itself, so SPEC-002's greedy shrink loop cannot spin.
     *
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<T>>
     */
    public function shrink(GeneratedValue $value): iterable;
}
