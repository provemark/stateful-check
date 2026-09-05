<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use LogicException;

/**
 * A bounded sequence of values from an item generator (SPEC-010 AC5).
 *
 * The same shape as StringsGenerator, deliberately: a length is drawn like any other bounded
 * integer and that many values are taken. The spec puts strings and lists in one document because
 * they are one idea — fast-check's `fc.string` is an array of characters and Hypothesis's
 * TextStrategy extends ListStrategy[str] — and the shared shape is what keeps the two shrinkers
 * from drifting apart.
 *
 * @implements Generator<list<mixed>>
 */
final class ListsGenerator implements Generator
{
    private readonly IntegersGenerator $length;

    /**
     * @param  Generator<mixed>  $item
     */
    public function __construct(private readonly Generator $item, int $min, int $max)
    {
        // Rejecting a bad length range is AC10's error path, not yet built.
        $this->length = new IntegersGenerator($min, $max);
    }

    /**
     * @return GeneratedValue<list<mixed>>
     */
    public function generate(Source $source): GeneratedValue
    {
        $length = $this->length->generate($source);

        $items = [];
        for ($i = 0; $i < $length->value; $i++) {
            $items[] = $this->item->generate($source)->value;
        }

        return new GeneratedValue($items, $length);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<list<mixed>>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        // AC6 is the next step, and it needs more than this: unlike a character, whose alphabet
        // index can be recovered from the character itself, an item's context is opaque and cannot
        // be reconstructed — so generate() will have to record the items' GeneratedValues too.
        // Until then this fails loudly rather than yielding nothing, which would be
        // indistinguishable from "already minimal" and would leave a counterexample un-shrunk.
        throw new LogicException('ListsGenerator::shrink() arrives with SPEC-010 AC6.');
    }
}
