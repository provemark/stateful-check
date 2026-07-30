<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ProvenanceChain;

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Outcome;

/**
 * A pure observation.
 *
 * Read earns its place in the alphabet — in the original it was effectively a no-op,
 * because the suite read after every command anyway — by asserting its own
 * postcondition: reading changes nothing, and a second read agrees with the first
 * (idempotent).
 *
 * Cost: two reads per Read command — one in run(), one in the postcondition — which
 * over a real service is two round-trips for a command that mutates nothing.
 *
 * @implements Command<ProvenanceModel, ProvenanceSession, Report>
 */
final readonly class Read implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        return $sut->read(); // report A
    }

    public function nextState(mixed $model): mixed
    {
        return $model; // reading changes nothing
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        $again = $sut->read(); // report B — must equal report A (idempotent, pure observation)

        return $outcome->value == $again
            && $again->hasManifest === $model->expectsManifest();
    }

    public function __toString(): string
    {
        return 'read()';
    }
}
