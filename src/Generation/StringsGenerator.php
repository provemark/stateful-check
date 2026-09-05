<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;
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

    /** @var list<string> */
    private readonly array $originCharacters;

    /**
     * @param  list<string>  $alphabet  single characters
     * @param  string|null  $origin  the string shrinking moves toward; defaults to $minLength
     *                               repetitions of the alphabet's first character (D032)
     */
    public function __construct(int $minLength, int $maxLength, array $alphabet, ?string $origin = null)
    {
        // Everything below is checked at CONSTRUCTION, never at generation, where a seeded run
        // would fail halfway through and the seed would be blamed for the caller's mistake (AC10).
        if ($minLength < 0) {
            throw new InvalidArgumentException("strings(): minLength ($minLength) must not be negative.");
        }

        if ($maxLength < $minLength) {
            throw new InvalidArgumentException(
                "strings(): maxLength ($maxLength) is below minLength ($minLength).",
            );
        }

        if ($alphabet === []) {
            throw new InvalidArgumentException('strings(): the alphabet must not be empty.');
        }

        foreach ($alphabet as $entry) {
            self::assertUtf8($entry, "strings(): alphabet entry '$entry'");

            if (count(self::charactersOf($entry)) !== 1) {
                throw new InvalidArgumentException(
                    "strings(): alphabet entry '$entry' must be a single character.",
                );
            }
        }

        if ($origin !== null) {
            self::assertUtf8($origin, "strings(): origin '$origin'");

            $originLength = count(self::charactersOf($origin));
            if ($originLength < $minLength || $originLength > $maxLength) {
                throw new InvalidArgumentException(
                    "strings(): explicit origin '$origin' is $originLength characters, outside [$minLength, $maxLength].",
                );
            }

            foreach (self::charactersOf($origin) as $character) {
                // D040: an origin drawn from outside the alphabet could only be reached by a
                // special-cased jump, producing shrink candidates this generator could never have
                // produced itself — the asymmetry the Generator contract exists to prevent.
                if (! in_array($character, $alphabet, true)) {
                    throw new InvalidArgumentException(
                        "strings(): origin character '$character' is not in the alphabet.",
                    );
                }
            }
        }

        $this->alphabet = $alphabet;
        $this->index = new IntegersGenerator(0, count($alphabet) - 1);

        $this->originCharacters = $origin === null
            ? array_fill(0, $minLength, $alphabet[0])
            : self::charactersOf($origin);

        // The deletion family's floor. With the default origin it equals $minLength, which is what
        // the implicit origin of integers() already gave, so no existing behaviour moves; with a
        // longer explicit origin it rises, because a candidate below the origin's length could
        // never reach the origin again — shrinking does not grow a value (AC4).
        $this->length = new IntegersGenerator(
            $minLength,
            $maxLength,
            max($minLength, count($this->originCharacters)),
        );
    }

    /**
     * Malformed UTF-8 is reported as such, and never as a length problem: preg_* with /u returns
     * false on it rather than raising, so a broken alphabet would otherwise produce a generator
     * that silently generates nothing. Telling the reader "more than one character" about a lone
     * lead byte would send them looking for a second character that does not exist (AC10).
     */
    private static function assertUtf8(string $value, string $subject): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException("$subject is not valid UTF-8.");
        }
    }

    /**
     * @return list<string>
     */
    private static function charactersOf(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? [] : $characters;
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
        $currentLength = count($characters);

        foreach ($this->length->shrink(new GeneratedValue($context->value)) as $shrunkLength) {
            // The length shrinks toward the origin's length, which sits ABOVE the value's own
            // length whenever a value shorter than a long explicit origin was drawn — D032 permits
            // such an origin, so this is reachable, not defensive. Those candidates would grow the
            // value, which is no reduction at all, so they are skipped and the deletion family is
            // simply empty for such a value.
            if ($shrunkLength->value >= $currentLength) {
                continue;
            }

            yield new GeneratedValue(
                implode('', array_slice($characters, 0, $shrunkLength->value)),
                $shrunkLength,
            );
        }

        // Simplification family: same length, ONE position moved toward the origin's character,
        // left to right. It comes after every shorter candidate because that ordering is the whole
        // value of the shrink for a report — a reader learns far more from "it fails at any
        // 3-character name" than from a 6-character one with simpler letters — and both fast-check
        // and Hypothesis order it this way (prior art, 2026-08-19).
        //
        // Each position delegates to the same integers(0, count-1) that drew it, exactly as
        // ElementsGenerator delegates its index, so "toward the alphabet's first character" is the
        // index generator's origin and not a rule this class states twice.
        foreach ($characters as $position => $character) {
            $index = array_search($character, $this->alphabet, true);
            if (! is_int($index)) {
                // A character outside the alphabet cannot come from this generator. Loud, like the
                // context checks above: a silent skip would weaken shrinking with no signal.
                throw new LogicException(sprintf(
                    'StringsGenerator::shrink() was handed the character %s, which is not in its alphabet.',
                    $character,
                ));
            }

            // Each position aims at the origin's character in that position. Beyond the origin's
            // end there is no corresponding character and the alphabet's first is used instead —
            // those positions are removed by the deletion family anyway. With the default origin
            // every target is the alphabet's first character, so this is one rule, not two.
            $target = $this->originCharacters[$position] ?? $this->alphabet[0];
            $targetIndex = array_search($target, $this->alphabet, true);
            if (! is_int($targetIndex)) {
                throw new LogicException(sprintf(
                    'StringsGenerator::shrink(): origin character %s is not in the alphabet.',
                    $target,
                ));
            }

            $toward = new IntegersGenerator(0, count($this->alphabet) - 1, $targetIndex);

            foreach ($toward->shrink(new GeneratedValue($index)) as $shrunkIndex) {
                $simplified = $characters;
                $simplified[$position] = $this->alphabet[$shrunkIndex->value];

                // The length is unchanged, so the candidate carries the same length context and
                // can be shrunk further — by deletion first, then simplification, all over again.
                yield new GeneratedValue(implode('', $simplified), $context);
            }
        }
    }
}
