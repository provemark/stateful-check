<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use Closure;
use LogicException;

/**
 * Applies a function to a generated value, and shrinks by delegating to the inner
 * generator and re-applying the function (SPEC-003 AC3).
 *
 * The inner GeneratedValue is the context. A non-injective function can map a shrunk
 * inner value back to the input value; those candidates are dropped so a candidate is
 * never the input value (strict ===, as with elements' dedup — see NOTES).
 *
 * @template TIn
 * @template TOut
 *
 * @implements Generator<TOut>
 */
final class MapGenerator implements Generator
{
    /** @var Closure(TIn): TOut */
    private readonly Closure $fn;

    /** @var Generator<TIn> */
    private readonly Generator $inner;

    /**
     * @param  callable(TIn): TOut  $fn
     * @param  Generator<TIn>  $inner
     */
    public function __construct(callable $fn, Generator $inner)
    {
        $this->fn = Closure::fromCallable($fn);
        $this->inner = $inner;
    }

    /**
     * @return GeneratedValue<TOut>
     */
    public function generate(Source $source): GeneratedValue
    {
        $inner = $this->inner->generate($source);

        return new GeneratedValue(($this->fn)($inner->value), $inner);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<TOut>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        $context = $value->context;
        if (! $context instanceof GeneratedValue) {
            // A context of the wrong shape is a generator bug, not user input. Fail
            // loudly rather than silently returning no candidates (SPEC-003 AC3).
            throw new LogicException(
                'MapGenerator::shrink() expects a GeneratedValue context (the inner value).',
            );
        }

        foreach ($this->inner->shrink($context) as $shrunkInner) {
            $mapped = ($this->fn)($shrunkInner->value);

            // Drop a non-injective function's collisions with the input value.
            if ($mapped === $value->value) {
                continue;
            }

            yield new GeneratedValue($mapped, $shrunkInner);
        }
    }
}
