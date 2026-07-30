<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ProvenanceChain;

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Outcome;

/**
 * Append a signing to the chain, then verify by reading the system back.
 *
 * The observation is a FRESH read of the system in the postcondition (Vorm 2), not
 * run()'s return value — this is what exercises D011 (a postcondition that reads the
 * SUT). Cost: one extra read per Sign; over a real service that is a full round-trip,
 * which is exactly the cost the D011 discipline is about.
 *
 * @implements Command<ProvenanceModel, ProvenanceSession, null>
 */
final readonly class Sign implements Command
{
    public function __construct(
        private string $agent,
        private ?string $version,
    ) {}

    public function preCondition(mixed $model): bool
    {
        return true; // re-signing an already signed asset is legitimate
    }

    public function run(mixed $sut): mixed
    {
        $sut->sign($this->agent, $this->version);

        return null; // the meaningful observation is the fresh read in postCondition
    }

    public function nextState(mixed $model): mixed
    {
        return $model->afterSign();
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        $report = $sut->read(); // D011: read the system fresh — one extra read per command

        if ($report->hasManifest !== $model->expectsManifest()) {
            return false;
        }

        if (! $model->expectsManifest()) {
            return ! $report->isAiGenerated && $report->digitalSourceTypes === [];
        }

        // The invariant the whole library exists to uphold: the AI marking survives
        // every further signing.
        return $report->isAiGenerated === $model->expectsAiMarking()
            && in_array('trainedAlgorithmicMedia', $report->digitalSourceTypes, true)
            && $report->isSignatureValid;
    }

    public function __toString(): string
    {
        return sprintf('sign(%s, %s)', var_export($this->agent, true), var_export($this->version, true));
    }
}
