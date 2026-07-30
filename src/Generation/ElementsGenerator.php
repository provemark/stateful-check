<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;

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
        // Keys carry no meaning — only order does.
        $choices = array_values($choices);

        // Deduplicate by value so a shrink candidate is never the input value (not
        // merely a different index). First occurrence wins; order is preserved.
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
     * @param  GeneratedValue<T>  $value
     * @return iterable<GeneratedValue<T>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        // The context is the chosen index's GeneratedValue (opaque `mixed` by design,
        // Step 12); narrow it at runtime before delegating the index shrink.
        $context = $value->context;
        if (! $context instanceof GeneratedValue || ! is_int($context->value)) {
            return;
        }

        foreach ($this->index->shrink(new GeneratedValue($context->value)) as $shrunkIndex) {
            yield new GeneratedValue($this->choices[$shrunkIndex->value], $shrunkIndex);
        }
    }
}
