<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

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

    /**
     * @param  list<mixed>  $choices
     */
    public function __construct(array $choices, int $min, int $max)
    {
        // Rejecting an empty choice set, a bad size range and a minimum larger than the choice set
        // is AC10's error path, not yet built.
        $this->choices = $choices;
        $this->size = new IntegersGenerator($min, $max);
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

        return new GeneratedValue(
            array_map(fn (int $index): mixed => $this->choices[$index], $indices),
            [$size, $indices],
        );
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<list<mixed>>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        // The rest of AC9 is the next step: removals before moving the remaining choices toward
        // earlier ones, never below the minimum, and never a duplicate at any point in the
        // sequence. Loud until then, as an empty candidate list would be indistinguishable from
        // "already minimal" and would leave a counterexample un-shrunk with no signal.
        throw new LogicException('SubsetGenerator::shrink() arrives with the rest of SPEC-010 AC9.');
    }
}
