<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

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
        // D015: an implicit (null) origin clamps into the range with no fuss. Rejecting an explicit
        // one outside it, and the range checks themselves, are AC10's error path — not yet built.
        $this->origin = $origin ?? max($min, min($max, 0.0));
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
