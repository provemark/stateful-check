<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;
use LogicException;

/**
 * Picks one of a fixed set of values, shrinking toward the first (SPEC-003 AC3).
 *
 * The chosen index is generated and shrunk by an internal `integers(0, count-1)`, so
 * `elements` shrinks toward index zero — the first element. That index (as a
 * GeneratedValue) is the value's context: the first place a context becomes tangible
 * (Step 12). Put the simplest or most ordinary value first; counterexamples reduce
 * toward it.
 *
 * @template T
 *
 * @implements Generator<T>
 */
final class ElementsGenerator implements Generator
{
    /** @var list<T> */
    private readonly array $choices;

    private readonly IntegersGenerator $index;

    /**
     * @param  array<array-key, T>  $choices
     */
    public function __construct(array $choices)
    {
        // One pass both deduplicates by value and normalizes the keys away: appending
        // to a fresh list drops the original keys (only order matters) and skips
        // repeats, so a shrink candidate is never the input value (not merely a
        // different index). First occurrence wins; order is preserved.
        $unique = [];
        foreach ($choices as $choice) {
            if (! in_array($choice, $unique, true)) {
                $unique[] = $choice;
            }
        }

        if ($unique === []) {
            throw new InvalidArgumentException('elements(): choices must not be empty.');
        }

        $this->choices = $unique;
        $this->index = new IntegersGenerator(0, count($unique) - 1);
    }

    /**
     * @return GeneratedValue<T>
     */
    public function generate(Source $source): GeneratedValue
    {
        $index = $this->index->generate($source);

        return new GeneratedValue($this->choices[$index->value], $index);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<T>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        // The context is the chosen index's GeneratedValue (opaque `mixed` by design,
        // Step 12); narrow it at runtime before delegating the index shrink.
        $context = $value->context;
        if (! $context instanceof GeneratedValue || ! is_int($context->value)) {
            // A context of the wrong shape is a bug in the generator that produced the
            // value, not user input. Fail loudly: silently returning no candidates would
            // stop shrinking and leave a counterexample un-shrunk with no signal — the
            // exact failure class this package exists to prevent. Same pattern in map
            // and associative.
            throw new LogicException(
                'ElementsGenerator::shrink() expects a GeneratedValue<int> context (the chosen index).',
            );
        }

        foreach ($this->index->shrink(new GeneratedValue($context->value)) as $shrunkIndex) {
            yield new GeneratedValue($this->choices[$shrunkIndex->value], $shrunkIndex);
        }
    }
}
