<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Generation;

/**
 * A generated value of type T plus whatever its generator needs to shrink it later.
 *
 * @template T
 */
final readonly class GeneratedValue
{
    /**
     * $context is deliberately `mixed` and stays that way — opaque to everyone but
     * the generator that produced it, exactly as fast-check keeps `Value.context`
     * as `unknown`. Do NOT template it (`@template TContext`): the opacity is the
     * design, and only the producing generator knows its shape and reads it in
     * shrink(). Null for a primitive whose value is self-describing (an integer);
     * a composite (elements, map, associative) stores the sub-value(s) it reduces.
     *
     * @param  T  $value
     */
    public function __construct(
        public mixed $value,
        public mixed $context = null,
    ) {}
}
