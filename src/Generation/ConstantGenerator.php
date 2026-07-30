<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

/**
 * The degenerate generator: one fixed value, no shrinking (SPEC-003 AC3 edge case).
 *
 * @template T
 *
 * @implements Generator<T>
 */
final class ConstantGenerator implements Generator
{
    /**
     * @param  T  $value
     */
    public function __construct(private readonly mixed $value) {}

    /**
     * @return GeneratedValue<T>
     */
    public function generate(Source $source): GeneratedValue
    {
        return new GeneratedValue($this->value);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<T>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        return [];
    }
}
