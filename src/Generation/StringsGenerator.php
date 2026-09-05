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
 * `preg_split('//u', …)` and PCRE's built-in Unicode support, which is where the one measurement
 * in this class lives.
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
        $length = $this->length->generate($source);

        $characters = [];
        for ($i = 0; $i < $length->value; $i++) {
            $characters[] = $this->alphabet[$this->index->generate($source)->value];
        }

        // The drawn length is the context: shrinking reduces it through the very generator that
        // produced it, so the deletion family inherits integers()'s ordering (the origin — here
        // minLength — first, then halving back toward the value) instead of inventing a second one.
        return new GeneratedValue(implode('', $characters), $length);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<string>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        $v = $value->value;
        $context = $value->context;
        if (! is_string($v) || ! $context instanceof GeneratedValue || ! is_int($context->value)) {
            // A context of the wrong shape is a generator bug, not user input. Fail loudly, as
            // elements/map/alphabet do: yielding nothing instead would be indistinguishable from
            // "already minimal" and would leave a counterexample un-shrunk with no signal.
            throw new LogicException(
                'StringsGenerator::shrink() expects a string with a GeneratedValue<int> length context.',
            );
        }

        // The one place a string has to be MEASURED rather than built, and the reason D031 costs no
        // runtime dependency: PCRE's Unicode support is compiled in, so no ext-mbstring is needed.
        // It returns false on malformed UTF-8 rather than raising, which is why AC10 validates the
        // alphabet at construction; reaching it here would be a generator bug, so it is loud too.
        $characters = preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new LogicException('StringsGenerator::shrink() was handed a value that is not valid UTF-8.');
        }

        // Deletion family (D036): every removal takes characters from the END, so a candidate is a
        // PREFIX of the value it came from. fast-check does the opposite — it keeps the suffix —
        // and a prefix is chosen deliberately: it is the version a reader of a failure report can
        // check by eye, and it matches how SPEC-002 shrinks a command sequence by holding a prefix.
        // Character simplification is the second family and arrives in the next step.
        foreach ($this->length->shrink(new GeneratedValue($context->value)) as $shrunkLength) {
            yield new GeneratedValue(
                implode('', array_slice($characters, 0, $shrunkLength->value)),
                $shrunkLength,
            );
        }
    }
}
