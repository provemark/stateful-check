<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;

/**
 * A bounded integer generator that shrinks toward an origin (SPEC-003 AC2).
 *
 * Shrinking is a pure function of the value: binary reduction toward the origin
 * (D005), closest-to-origin first, finite, never the input. The generator holds the
 * origin, so a generated value carries a null context (see GeneratedValue).
 *
 * @implements Generator<int>
 */
final class IntegersGenerator implements Generator
{
    private readonly int $origin;

    public function __construct(
        private readonly int $min,
        private readonly int $max,
        ?int $origin = null,
    ) {
        // D016: the range width must fit in a PHP int. Checked WITHOUT subtracting,
        // which on the full range would overflow to a float and measure nothing.
        if ($min < 0 && $max > PHP_INT_MAX + $min) {
            throw new InvalidArgumentException(
                'integers(): range width exceeds PHP_INT_MAX; bound the range.',
            );
        }

        // D015: an implicit (null) origin clamps into the range with no fuss; an
        // explicit origin outside the range is a caller error and throws.
        if ($origin === null) {
            $this->origin = max($min, min($max, 0));
        } elseif ($origin < $min || $origin > $max) {
            throw new InvalidArgumentException(
                "integers(): explicit origin ($origin) is outside [$min, $max].",
            );
        } else {
            $this->origin = $origin;
        }
    }

    /**
     * @return GeneratedValue<int>
     */
    public function generate(Source $source): GeneratedValue
    {
        return new GeneratedValue($source->nextInt($this->min, $this->max));
    }

    /**
     * @param  GeneratedValue<int>  $value
     * @return iterable<GeneratedValue<int>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        $v = $value->value;

        if ($v === $this->origin) {
            return;
        }

        // Closest to the origin first: the origin itself is the biggest jump.
        yield new GeneratedValue($this->origin);

        // Then halve the remaining distance toward $v. Repeated halving, never
        // 2 ** $k — with a near-PHP_INT_MAX gap the exponent reaches 63, where the
        // power becomes a float and intdiv() throws (D016). intdiv truncates toward
        // zero, so this is symmetric for a positive or negative distance.
        $step = $v - $this->origin;
        while (true) {
            $step = intdiv($step, 2);
            if ($step === 0) {
                break;
            }
            yield new GeneratedValue($v - $step);
        }
    }
}
