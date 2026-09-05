<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;
use LogicException;

/**
 * A bounded list of DISTINCT members of a fixed choice set (SPEC-010 AC9).
 *
 * Narrow on purpose (D034): it covers JSON Schema's `uniqueItems` where the item schema is a
 * finite, enumerable set, and nothing else. The prior art shows why there is no free general
 * version — fast-check deduplicates shrink candidates with a filter that only drops items, leaving
 * candidates below the requested length, and Hypothesis aborts the test case when it cannot draw a
 * fresh element. For a consumer whose promise is that every generated value satisfies its schema,
 * the first is an invalid value presented as valid and the second is nondeterminism inside a
 * seeded run. Over a finite choice set neither problem arises, because the choices can be indexed.
 *
 * Distinctness is therefore not enforced by retrying or filtering: a size is drawn, then that many
 * DISTINCT indices are taken by partial selection over the choice set, so a repeat is impossible
 * rather than rejected.
 *
 * @implements Generator<list<mixed>>
 */
final class SubsetGenerator implements Generator
{
    /** @var list<mixed> */
    private readonly array $choices;

    private readonly IntegersGenerator $size;

    private readonly IntegersGenerator $index;

    /**
     * @param  list<mixed>  $choices
     */
    public function __construct(array $choices, int $min, int $max)
    {
        // At CONSTRUCTION, never at generation (AC10).
        if ($choices === []) {
            throw new InvalidArgumentException('subsetOf(): choices must not be empty.');
        }

        if ($min < 0) {
            throw new InvalidArgumentException("subsetOf(): min ($min) must not be negative.");
        }

        if ($max < $min) {
            throw new InvalidArgumentException("subsetOf(): max ($max) is below min ($min).");
        }

        // Not malformed but UNSATISFIABLE: distinctness is constructed, not filtered, so there is
        // no way to draw more distinct members than the set holds. Left unchecked this would fail
        // during generation, where the seed would be blamed for the caller's mistake. The maximum
        // needs no such check — it is an upper bound, and a draw simply never reaches it.
        if ($min > count($choices)) {
            throw new InvalidArgumentException(sprintf(
                'subsetOf(): min (%d) exceeds the %d available choices.',
                $min,
                count($choices),
            ));
        }

        $this->choices = $choices;
        $this->size = new IntegersGenerator($min, $max);
        $this->index = new IntegersGenerator(0, count($choices) - 1);
    }

    /**
     * @return GeneratedValue<list<mixed>>
     */
    public function generate(Source $source): GeneratedValue
    {
        $size = $this->size->generate($source);

        // Partial Fisher-Yates over the indices: each step picks from what is left, so the result
        // is distinct by construction and the draw stays a fixed number of source reads — no
        // retry loop whose length would depend on luck (R4).
        $pool = array_keys($this->choices);
        $indices = [];
        for ($i = 0; $i < $size->value; $i++) {
            $pick = $source->nextInt($i, count($pool) - 1);
            [$pool[$i], $pool[$pick]] = [$pool[$pick], $pool[$i]];
            $indices[] = $pool[$i];
        }

        return new GeneratedValue($this->valuesOf($indices), [$size, $indices]);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<list<mixed>>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        $context = $value->context;
        if (
            ! is_array($context) || ! array_is_list($context) || count($context) !== 2
            || ! $context[0] instanceof GeneratedValue || ! is_int($context[0]->value)
            || ! is_array($context[1]) || ! array_is_list($context[1])
        ) {
            throw new LogicException(
                'SubsetGenerator::shrink() expects a [GeneratedValue<int> size, list of int indices] context.',
            );
        }

        $size = $context[0];

        $indices = [];
        foreach ($context[1] as $index) {
            if (! is_int($index)) {
                throw new LogicException('SubsetGenerator::shrink() expects every recorded index to be an int.');
            }

            $indices[] = $index;
        }

        // Removals first, from the end (D036), through the same integers() that drew the size — so
        // no candidate falls below the minimum and a candidate is a prefix, as everywhere else here.
        foreach ($this->size->shrink(new GeneratedValue($size->value)) as $shrunkSize) {
            $kept = array_slice($indices, 0, $shrunkSize->value);

            yield new GeneratedValue($this->valuesOf($kept), [$shrunkSize, $kept]);
        }

        // Then the remaining choices move toward earlier ones, one position at a time, delegating
        // to the same integers(0, count-1) that drew the index — "earlier" is that generator's
        // origin, not a rule stated twice.
        foreach ($indices as $position => $index) {
            foreach ($this->index->shrink(new GeneratedValue($index)) as $shrunkIndex) {
                $candidate = [];
                foreach ($indices as $each => $existing) {
                    $candidate[] = $each === $position ? $shrunkIndex->value : $existing;
                }

                // A move onto a choice the subset already holds is SKIPPED, not offered and then
                // filtered: a duplicate must not exist at any point in the sequence, and a
                // candidate that is dropped afterwards has still been counted as offered. This is
                // where the finite choice set earns its keep — the collision is decidable here,
                // which is exactly what a general uniqueItems over an arbitrary item generator
                // cannot promise (D034).
                if (count(array_unique($candidate, SORT_REGULAR)) !== count($candidate)) {
                    continue;
                }

                yield new GeneratedValue($this->valuesOf($candidate), [$size, $candidate]);
            }
        }
    }

    /**
     * @param  list<int>  $indices
     * @return list<mixed>
     */
    private function valuesOf(array $indices): array
    {
        return array_map(fn (int $index): mixed => $this->choices[$index], $indices);
    }
}
