<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;
use Provemark\StatefulCheck\Command;

/**
 * Uniform choice over a command alphabet, shrinking each command's arguments through the
 * generator that produced it (SPEC-003 AC5). A thin delegation to OneOfGenerator, which holds the
 * mechanism this class used to contain: SPEC-010 AC1 extracted it so plain-value generators can
 * use it too, and this class keeps only its Command typing, its own empty-alphabet message, and
 * unchanged behaviour — same values and same shrink sequence for the same seed.
 *
 * The branch choice itself is not shrunk — a documented coverage gap (AC5), not a division of
 * labour: no layer simplifies "which command", so a shrunk counterexample may keep a more complex
 * command where a simpler alphabet entry would also have failed.
 *
 * @template TModel
 * @template TSut
 *
 * @implements Generator<Command<TModel, TSut, mixed>>
 */
final class AlphabetGenerator implements Generator
{
    /** @var OneOfGenerator<Command<TModel, TSut, mixed>> */
    private readonly OneOfGenerator $oneOf;

    /**
     * @param  list<Generator<Command<TModel, TSut, mixed>>>  $branches
     */
    public function __construct(array $branches)
    {
        // Checked here rather than left to oneOf(), so the message still names the alphabet:
        // a user who called Gen::alphabet() should not be told about a combinator they never used.
        if ($branches === []) {
            throw new InvalidArgumentException('alphabet(): the alphabet must not be empty.');
        }

        $this->oneOf = new OneOfGenerator($branches);
    }

    /**
     * @return GeneratedValue<Command<TModel, TSut, mixed>>
     */
    public function generate(Source $source): GeneratedValue
    {
        return $this->oneOf->generate($source);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<Command<TModel, TSut, mixed>>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        return $this->oneOf->shrink($value);
    }
}
