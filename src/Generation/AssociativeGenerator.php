<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use LogicException;

/**
 * Generates a keyed record — one value per generator — and shrinks one component at a
 * time, delegating each to its own generator (SPEC-003 AC3).
 *
 * Shrinking is one key at a time in array order. The order is semantic: it steers where
 * the greedy loop converges, like `elements`' choice order. Reducing one key at a time
 * is a documented local minimum (R3) — a bug coupling two keys cannot be reduced this
 * way. See NOTES.
 *
 * The record is heterogeneous, so it is typed `array<array-key, mixed>`. The generators
 * are `Generator<mixed>`; because `Generator` is covariant (D017), a caller may pass a
 * heterogeneous set of concrete generators here.
 *
 * Keys may also be OPTIONAL — present in some values, absent in others (SPEC-010 AC7). They are a
 * second parameter rather than a separate `record()` combinator (D037): it adds no new concept and
 * leaves every existing call untouched. Presence is drawn per key by an internal integers(0, 1)
 * whose origin is 0, so absence is where shrinking aims (AC8), the same way elements() aims at
 * index 0.
 *
 * @implements Generator<array<array-key, mixed>>
 */
final class AssociativeGenerator implements Generator
{
    private readonly IntegersGenerator $presence;

    /**
     * @param  array<array-key, Generator<mixed>>  $generators  always present
     * @param  array<array-key, Generator<mixed>>  $optional  sometimes present
     */
    public function __construct(
        private readonly array $generators,
        private readonly array $optional = [],
    ) {
        // Rejecting a key that is both required and optional is AC10's error path, not yet built.
        $this->presence = new IntegersGenerator(0, 1);
    }

    /**
     * @return GeneratedValue<array<array-key, mixed>>
     */
    public function generate(Source $source): GeneratedValue
    {
        $values = [];
        $context = [];

        foreach ($this->generators as $key => $generator) {
            $component = $generator->generate($source);
            $values[$key] = $component->value;
            $context[$key] = $component;
        }

        // Optional keys come after the required ones, in declaration order, so the key order of
        // the produced record is a function of the declaration and the draw — never of hash order
        // or anything else that varies between processes (R4).
        foreach ($this->optional as $key => $generator) {
            $present = $this->presence->generate($source);
            $component = $present->value === 1 ? $generator->generate($source) : null;

            if ($component !== null) {
                $values[$key] = $component->value;
            }

            // The context records the presence draw for every optional key, present or not, so
            // shrinking can tell "absent" from "never asked" — and carries the component beside it
            // so an absent key costs nothing and a present one stays shrinkable (AC8).
            $context[$key] = [$present, $component];
        }

        return new GeneratedValue($values, $context);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<array<array-key, mixed>>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        $record = $value->value;
        $context = $value->context;
        if (! is_array($record) || ! is_array($context)) {
            throw new LogicException(
                'AssociativeGenerator::shrink() expects an array record and an array<key, GeneratedValue> context.',
            );
        }

        // One key at a time, in array order (semantic; local minimum, R3).
        foreach ($this->generators as $key => $generator) {
            $component = $context[$key] ?? null;
            if (! $component instanceof GeneratedValue) {
                throw new LogicException(
                    "AssociativeGenerator::shrink() expects a GeneratedValue context for key '$key'.",
                );
            }

            foreach ($generator->shrink($component) as $shrunkComponent) {
                $values = $record;
                $values[$key] = $shrunkComponent->value;

                $newContext = $context;
                $newContext[$key] = $shrunkComponent;

                yield new GeneratedValue($values, $newContext);
            }
        }

        // Optional keys, in declaration order: absence first, then the value (SPEC-010 AC8).
        foreach ($this->optional as $key => $generator) {
            $entry = $context[$key] ?? null;
            if (
                ! is_array($entry) || ! array_is_list($entry) || count($entry) !== 2
                || ! $entry[0] instanceof GeneratedValue || ! is_int($entry[0]->value)
                || ! ($entry[1] === null || $entry[1] instanceof GeneratedValue)
            ) {
                throw new LogicException(
                    "AssociativeGenerator::shrink() expects a [GeneratedValue<int> presence, GeneratedValue|null] context for optional key '$key'.",
                );
            }

            [$present, $component] = $entry;

            // Absence is the presence draw shrinking to its own origin, not a special case written
            // here: integers(0, 1) yields 0 for a present key and NOTHING for an absent one. That
            // is what makes AC8's hardest clause structural — once the key is gone, this loop has
            // no candidate to offer, so nothing can re-add it or repeat the record.
            foreach ($this->presence->shrink(new GeneratedValue($present->value)) as $shrunkPresence) {
                $values = $record;
                unset($values[$key]);

                $newContext = $context;
                $newContext[$key] = [$shrunkPresence, null];

                yield new GeneratedValue($values, $newContext);
            }

            // Then, and only then, the value itself — the bigger reduction is offered first.
            if ($component === null) {
                continue;
            }

            foreach ($generator->shrink($component) as $shrunkComponent) {
                $values = $record;
                $values[$key] = $shrunkComponent->value;

                $newContext = $context;
                $newContext[$key] = [$present, $shrunkComponent];

                yield new GeneratedValue($values, $newContext);
            }
        }
    }
}
