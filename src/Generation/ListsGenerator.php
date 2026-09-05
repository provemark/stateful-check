<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;
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
        // At CONSTRUCTION, never at generation: a seeded run that fails halfway through blames the
        // seed for what is the caller's mistake (AC10). A negative minimum is checked here rather
        // than left to integers(), whose message would name a generator the caller never used.
        if ($min < 0) {
            throw new InvalidArgumentException("listsOf(): min ($min) must not be negative.");
        }

        if ($max < $min) {
            throw new InvalidArgumentException("listsOf(): max ($max) is below min ($min).");
        }

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
            $items[] = $this->item->generate($source);
        }

        // The context is composite — [the drawn length, the items' own GeneratedValues] — because
        // an item's context is opaque and cannot be recovered from the item itself. Without it a
        // candidate could not be shrunk any further, which is why this differs from
        // StringsGenerator, where a character's alphabet index is recoverable from the character.
        return new GeneratedValue(
            array_map(static fn (GeneratedValue $item): mixed => $item->value, $items),
            [$length, $items],
        );
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
            // A context of the wrong shape is a generator bug, not user input. Fail loudly, as
            // elements/map/alphabet do: yielding nothing would be indistinguishable from "already
            // minimal" and would leave a counterexample un-shrunk with no signal.
            throw new LogicException(
                'ListsGenerator::shrink() expects a [GeneratedValue<int> length, list of GeneratedValue items] context.',
            );
        }

        $length = $context[0];

        // Each recorded item must itself be a GeneratedValue — checked one by one rather than
        // assumed, because the whole point of the composite context is that the items carry their
        // own opaque contexts. A list holding anything else is a generator bug of the same class
        // as a malformed context, so it fails the same way.
        $items = [];
        foreach ($context[1] as $item) {
            if (! $item instanceof GeneratedValue) {
                throw new LogicException(
                    'ListsGenerator::shrink() expects every recorded item to be a GeneratedValue.',
                );
            }

            $items[] = $item;
        }

        // Deletion family (D036): every removal takes elements from the END, so a candidate is a
        // PREFIX of the list it came from. fast-check keeps the suffix instead; a prefix is what a
        // reader of a failure report can check by eye, and it matches how SPEC-002 shrinks a
        // command sequence. Element shrinking is the second family and arrives in the next step.
        foreach ($this->length->shrink(new GeneratedValue($length->value)) as $shrunkLength) {
            $kept = array_slice($items, 0, $shrunkLength->value);

            // The kept items carry their own contexts along, so a candidate can be shrunk again —
            // by deletion first, then by element. Dropping them here would silently end shrinking
            // one step in.
            yield new GeneratedValue(
                array_map(static fn (GeneratedValue $item): mixed => $item->value, $kept),
                [$shrunkLength, $kept],
            );
        }

        // Element family: same length, ONE element shrunk through the item generator, left to
        // right. It comes after every shorter candidate because a reader learns far more from "it
        // fails with any two items" than from four simpler ones, and both fast-check and
        // Hypothesis order it this way (prior art, 2026-08-19).
        //
        // This class contributes the position and nothing else: what an item shrinks to is the
        // item generator's business, including its origin. That division of labour is what lets a
        // list of anything be shrunk without this class knowing what it holds. One element at a
        // time is the same reduction associative() makes, with the same documented local minimum
        // (R3): a bug needing two elements reduced together is not found by this family.
        foreach ($items as $position => $item) {
            foreach ($this->item->shrink($item) as $shrunkItem) {
                // Rebuilt rather than copied-and-assigned: writing at a variable offset turns the
                // list into a keyed array as far as static analysis is concerned, and the value
                // this generator promises is a list. Same result, one honest type.
                $candidate = [];
                foreach ($items as $index => $each) {
                    $candidate[] = $index === $position ? $shrunkItem : $each;
                }

                yield new GeneratedValue(
                    array_map(static fn (GeneratedValue $each): mixed => $each->value, $candidate),
                    [$length, $candidate],
                );
            }
        }
    }
}
