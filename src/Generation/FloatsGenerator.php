<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;
use LogicException;

/**
 * A bounded float generator that shrinks toward an origin (SPEC-010 AC2).
 *
 * Modelled on IntegersGenerator, deliberately: the origin comes first — the biggest jump, which is
 * what makes termination trivial — and the remaining distance is then halved back toward the value,
 * the greedy loop's retry-less-aggressively order (D038). Simplifying toward "nice" decimals, as
 * fast-check does, reads better in a report but needs a definition of *nice* this engine has no
 * precedent for, and it would put a second shrink model beside the first.
 *
 * Two things differ from the integer case, and both are why AC2 states the step bound as a
 * requirement. Halving an int reaches zero through intdiv and stops on its own; halving a float
 * reaches zero only through the denormals, after roughly a thousand steps. And rounding can make a
 * candidate equal its predecessor or the value it came from, which the Generator contract forbids.
 * So the loop has a fixed cap and each candidate must lie strictly between its predecessor and the
 * value; the first candidate that does not ends the sequence.
 *
 * @implements Generator<float>
 */
final class FloatsGenerator implements Generator
{
    /**
     * Enough halvings to bisect any range a test will hold — a double has 53 bits of mantissa, so
     * 32 steps is a deep search — and small enough to keep a shrink sequence short. The cap is a
     * bound, not a target: the strictly-between test usually ends the sequence first.
     */
    private const MAX_STEPS = 32;

    private readonly float $origin;

    public function __construct(
        private readonly float $min,
        private readonly float $max,
        ?float $origin = null,
    ) {
        // Every check is at CONSTRUCTION, never at generation: a seeded run that fails halfway
        // through blames the seed for what is the caller's mistake (AC10).
        if (! is_finite($min) || ! is_finite($max)) {
            throw new InvalidArgumentException(
                sprintf('floats(): bounds must be finite, got [%s, %s].', self::describe($min), self::describe($max)),
            );
        }

        if ($max < $min) {
            throw new InvalidArgumentException(
                sprintf('floats(): max (%s) is below min (%s).', self::describe($max), self::describe($min)),
            );
        }

        // D016 in its float form. Checked as a width rather than by comparing bounds, because a
        // range can be finite at both ends and still have no representable width — and every value
        // drawn from it would then be INF or NAN, which AC2 forbids outright.
        if (! is_finite($max - $min)) {
            throw new InvalidArgumentException(
                sprintf(
                    'floats(): range width [%s, %s] is not representable; bound the range.',
                    self::describe($min),
                    self::describe($max),
                ),
            );
        }

        // D015: an implicit (null) origin clamps into the range with no fuss; an explicit one
        // outside it is a caller error and throws.
        if ($origin === null) {
            $this->origin = max($min, min($max, 0.0));
        } elseif (! is_finite($origin) || $origin < $min || $origin > $max) {
            throw new InvalidArgumentException(
                sprintf(
                    'floats(): explicit origin (%s) is outside [%s, %s].',
                    self::describe($origin),
                    self::describe($min),
                    self::describe($max),
                ),
            );
        } else {
            $this->origin = $origin;
        }
    }

    /**
     * NAN and INF cannot be cast to string without a warning, and a message about a bad bound is
     * exactly where they turn up. Finite values render as they always did, so only the case that
     * needed handling is handled.
     */
    private static function describe(float $value): string
    {
        return is_finite($value) ? (string) $value : var_export($value, true);
    }

    /**
     * @return GeneratedValue<float>
     */
    public function generate(Source $source): GeneratedValue
    {
        // A uniform fraction in [0, 1) built from the one primitive Source offers, then scaled into
        // the range. Drawing at the int's full width rather than over a coarse grid is the point of
        // owning floats at all (D033): a grid would make every value a multiple of a step size,
        // which is exactly the disclosure the cut version would have forced on the consumer.
        $fraction = $source->nextInt(0, PHP_INT_MAX) / (PHP_INT_MAX + 1.0);

        return new GeneratedValue($this->min + ($this->max - $this->min) * $fraction);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<float>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        $v = $value->value;
        if (! is_float($v)) {
            // The contract passes GeneratedValue<mixed> (D017); a non-float value here is a
            // generator bug, not user input. Fail loudly, as integers/elements/map do.
            throw new LogicException('FloatsGenerator::shrink() expects a GeneratedValue<float>.');
        }

        if ($v === $this->origin) {
            return;
        }

        yield new GeneratedValue($this->origin);

        $step = $v - $this->origin;
        $previous = $this->origin;

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $step /= 2.0;
            $candidate = $v - $step;

            // Strictly between the predecessor and the value: both differences share a sign and
            // neither is zero. One test for three promises — never the value it came from, never a
            // repeat of the predecessor, always on the origin's side of the value.
            if (($candidate - $previous) * ($v - $candidate) <= 0.0) {
                return;
            }

            yield new GeneratedValue($candidate);
            $previous = $candidate;
        }
    }
}
