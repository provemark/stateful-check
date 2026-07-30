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
 * @implements Generator<array<array-key, mixed>>
 */
final class AssociativeGenerator implements Generator
{
    /**
     * @param  array<array-key, Generator<mixed>>  $generators
     */
    public function __construct(private readonly array $generators) {}

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
    }
}
