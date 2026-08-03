<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;
use LogicException;

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
        private readonly int $edgeBias = 0,   // percent 0..100; 0 = pure uniform (D029). AC6 guards the range.
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

        // AC6: edgeBias is a percentage; a value outside [0, 100] is a caller error (0 = off, D029).
        if ($edgeBias < 0 || $edgeBias > 100) {
            throw new InvalidArgumentException(
                "integers(): edgeBias ($edgeBias) must be a percentage in [0, 100].",
            );
        }
    }

    /**
     * @return GeneratedValue<int>
     */
    public function generate(Source $source): GeneratedValue
    {
        // Edge bias (SPEC-009 AC1): with `edgeBias` percent probability, draw a boundary value instead of
        // a uniform one, where bugs cluster. The `> 0` short-circuit is load-bearing (D029): when the bias
        // is off, no bias decision is drawn from the source, so the draw sequence — and every existing seed
        // — is byte-for-byte unchanged. The decision and the edge index both come from the source, so the
        // biased draw stays deterministic and reproducible (R4). The context is null exactly as a uniform
        // draw's, so shrinking is oblivious to how the value was drawn.
        if ($this->edgeBias > 0 && $source->nextInt(0, 99) < $this->edgeBias) {
            $edges = $this->edgeSet();

            return new GeneratedValue($edges[$source->nextInt(0, count($edges) - 1)]);
        }

        return new GeneratedValue($source->nextInt($this->min, $this->max));
    }

    /**
     * The boundary values to bias toward, derived from the range the generator already knows: the origin
     * (where shrinking terminates) and the two bounds. Deduplicated and re-indexed to a list — for a range
     * whose origin equals a bound (e.g. `integers(0, n)`), the set collapses accordingly. Neighbours
     * (origin±1, min+1, max-1) are OQ3, added only if a planted neighbour-bug at AC5 needs them.
     *
     * @return list<int>
     */
    private function edgeSet(): array
    {
        return array_values(array_unique([$this->origin, $this->min, $this->max]));
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<int>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        $v = $value->value;
        if (! is_int($v)) {
            // The contract passes GeneratedValue<mixed> (D017); a non-int value here is
            // a generator bug, not user input. Fail loudly, as elements/map/associative.
            throw new LogicException('IntegersGenerator::shrink() expects a GeneratedValue<int>.');
        }

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
