<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;
use LogicException;
use Provemark\StatefulCheck\Command;

/**
 * Uniform choice over a command alphabet, shrinking each command's arguments through the
 * generator that produced it (SPEC-003 AC5). Internally a `oneOf` over the branch generators —
 * the combinator the 2026-07-30 audit removed from the user-facing surface, returned here as the
 * mechanism the scope said it would be.
 *
 * The chosen branch is picked by an internal `integers(0, count-1)` and RECORDED in the context
 * as `[index, the branch's own GeneratedValue]`, so `shrink()` delegates to the exact generator
 * that produced the command. The branch choice itself is not shrunk — a documented coverage gap
 * (AC5), not a division of labour: no layer simplifies "which command", so a shrunk counterexample
 * may keep a more complex command where a simpler alphabet entry would also have failed.
 *
 * @template TModel
 * @template TSut
 *
 * @implements Generator<Command<TModel, TSut, mixed>>
 */
final class AlphabetGenerator implements Generator
{
    /** @var list<Generator<Command<TModel, TSut, mixed>>> */
    private readonly array $branches;

    private readonly IntegersGenerator $index;

    /**
     * @param  list<Generator<Command<TModel, TSut, mixed>>>  $branches
     */
    public function __construct(array $branches)
    {
        if ($branches === []) {
            throw new InvalidArgumentException('alphabet(): the alphabet must not be empty.');
        }

        $this->branches = $branches;
        $this->index = new IntegersGenerator(0, count($branches) - 1);
    }

    /**
     * @return GeneratedValue<Command<TModel, TSut, mixed>>
     */
    public function generate(Source $source): GeneratedValue
    {
        $index = $this->index->generate($source);
        $branchValue = $this->branches[$index->value]->generate($source);

        return new GeneratedValue($branchValue->value, [$index->value, $branchValue]);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<Command<TModel, TSut, mixed>>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        // First generator with a COMPOSITE context: [chosen index, the branch's GeneratedValue].
        // A context of the wrong shape is a generator bug; fail loudly, as elements/map do.
        $context = $value->context;
        if (
            ! is_array($context) || ! array_is_list($context) || count($context) !== 2
            || ! is_int($context[0]) || ! $context[1] instanceof GeneratedValue
        ) {
            throw new LogicException(
                'AlphabetGenerator::shrink() expects a [int index, GeneratedValue] context.',
            );
        }

        [$index, $branchValue] = $context;

        // An index outside the branches is the composite context's own failure mode. Falling back
        // to branch 0 or an empty list would be exactly the silent degradation that turns off
        // shrinking unnoticed, so it is a loud LogicException naming the index and the branch count.
        if ($index < 0 || $index >= count($this->branches)) {
            throw new LogicException(sprintf(
                'AlphabetGenerator::shrink(): branch index %d is out of range for %d branches.',
                $index,
                count($this->branches),
            ));
        }

        foreach ($this->branches[$index]->shrink($branchValue) as $shrunk) {
            yield new GeneratedValue($shrunk->value, [$index, $shrunk]);
        }
    }
}
