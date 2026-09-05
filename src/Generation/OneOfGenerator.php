<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

use InvalidArgumentException;
use LogicException;

/**
 * Uniform choice among branches of any type (SPEC-010 AC1).
 *
 * This is the mechanism AlphabetGenerator has carried since SPEC-003 AC5, extracted without its
 * Command typing so a heterogeneous set of plain-value generators can use it — the 2026-07-30
 * audit removed `oneOf` from the user-facing surface, not from the code, and SPEC-010 returns it
 * on the terms that removal set. AlphabetGenerator now delegates here and keeps its typed
 * signature.
 *
 * The chosen branch is picked by an internal `integers(0, count-1)` and RECORDED in the context as
 * `[index, the branch's own GeneratedValue]`, so `shrink()` delegates to the exact generator that
 * produced the value and never replaces it with another. The branch choice itself is not shrunk —
 * a documented coverage gap (SPEC-003 AC5), unchanged by this extraction: no layer simplifies
 * "which branch", so a shrunk counterexample may keep a value from a more complex branch where a
 * simpler one would also have failed.
 *
 * @template-covariant T
 *
 * @implements Generator<T>
 */
final class OneOfGenerator implements Generator
{
    /** @var list<Generator<T>> */
    private readonly array $branches;

    private readonly IntegersGenerator $index;

    /**
     * @param  list<Generator<T>>  $branches
     */
    public function __construct(array $branches)
    {
        if ($branches === []) {
            throw new InvalidArgumentException('oneOf(): there must be at least one branch.');
        }

        $this->branches = $branches;
        $this->index = new IntegersGenerator(0, count($branches) - 1);
    }

    /**
     * @return GeneratedValue<T>
     */
    public function generate(Source $source): GeneratedValue
    {
        $index = $this->index->generate($source);
        $branchValue = $this->branches[$index->value]->generate($source);

        return new GeneratedValue($branchValue->value, [$index->value, $branchValue]);
    }

    /**
     * @param  GeneratedValue<mixed>  $value
     * @return iterable<GeneratedValue<T>>
     */
    public function shrink(GeneratedValue $value): iterable
    {
        // A COMPOSITE context: [chosen index, the branch's GeneratedValue]. A context of the wrong
        // shape is a generator bug; fail loudly, as elements/map do.
        $context = $value->context;
        if (
            ! is_array($context) || ! array_is_list($context) || count($context) !== 2
            || ! is_int($context[0]) || ! $context[1] instanceof GeneratedValue
        ) {
            throw new LogicException(
                'OneOfGenerator::shrink() expects a [int index, GeneratedValue] context.',
            );
        }

        [$index, $branchValue] = $context;

        // An index outside the branches is the composite context's own failure mode. Falling back
        // to branch 0 or an empty list would be exactly the silent degradation that turns off
        // shrinking unnoticed, so it is a loud LogicException naming the index and the branch count.
        if ($index < 0 || $index >= count($this->branches)) {
            throw new LogicException(sprintf(
                'OneOfGenerator::shrink(): branch index %d is out of range for %d branches.',
                $index,
                count($this->branches),
            ));
        }

        foreach ($this->branches[$index]->shrink($branchValue) as $shrunk) {
            yield new GeneratedValue($shrunk->value, [$index, $shrunk]);
        }
    }
}
