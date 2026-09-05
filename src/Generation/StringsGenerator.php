<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use LogicException;

/**
 * A string of characters drawn from a supplied alphabet (SPEC-010 AC3).
 *
 * A string here is what the spec says it is: a sequence of *n* characters, with *n* drawn like any
 * other bounded integer. That is why generation never has to MEASURE a string — the length is known
 * because it was chosen — and therefore why counting in code points (D031) costs no runtime
 * dependency: `strlen`, `substr` and `str_split` appear nowhere in this path, and `mb_*` is not
 * needed either. Only shrinking has to split a string back into characters, which AC4 will do with
 * `preg_split('//u', …)` and PCRE's built-in Unicode support.
 *
 * The alphabet is required, with no default (D035): the engine has no opinion about which
 * characters are interesting, and a shipped default would be a promise every recorded seed in the
 * world depends on. Adding one later is backwards compatible; changing one is not.
 *
 * @implements Generator<string>
 */
final class StringsGenerator implements Generator
{
    /** @var list<string> */
    private readonly array $alphabet;

    private readonly IntegersGenerator $length;

    private readonly IntegersGenerator $index;

    /**
     * @param  list<string>  $alphabet  single characters; shrinks toward the first (AC4)
     */
    public function __construct(int $minLength, int $maxLength, array $alphabet)
    {
        // Rejecting a bad length range, an empty alphabet, a multi-character entry and invalid
        // UTF-8 is AC10's error path, not yet built.
        $this->alphabet = $alphabet;
        $this->length = new IntegersGenerator($minLength, $maxLength);
        $this->index = new IntegersGenerator(0, count($alphabet) - 1);
    }

    /**
     * @return GeneratedValue<string>
     */
    public function generate(Source $source): GeneratedValue
    {
        $length = $this->length->generate($source)->value;

        $characters = [];
        for ($i = 0; $i < $length; $i++) {
            $characters[] = $this->alphabet[$this->index->generate($source)->value];
        }

        return new GeneratedValue(implode('', $characters));
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<string>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        // AC4 is the next step. Until it exists, this fails loudly rather than yielding nothing:
        // an empty candidate list is indistinguishable from "already minimal" and would leave a
        // counterexample un-shrunk with no signal — the silent degradation R8 and D024 exist to
        // prevent. D024's binding condition is that a generator ships WITH its origin-ward shrink,
        // which is a promise about the finished spec, not about this intermediate commit.
        throw new LogicException('StringsGenerator::shrink() arrives with SPEC-010 AC4.');
    }
}
