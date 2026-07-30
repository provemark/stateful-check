<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ProvenanceChain;

/**
 * A self-contained, in-memory stand-in for the provenance signing service (D014).
 *
 * The mutable system under test: signing appends to the chain, reading observes it,
 * and state accumulates across commands — the shape a real HTTP service has, minus
 * the transport. It is a correct implementation on purpose: a dogfood example must
 * pass; hunting planted bugs is the meta-suite's job (R8), not the example's.
 *
 * What the fake cannot reproduce is noted in NOTES: no real latency or flakiness, so
 * the skip-when-unreachable guard never fires and the per-command reads are free
 * rather than round-trips.
 */
final class ProvenanceSession
{
    private const TRAINED = 'trainedAlgorithmicMedia';

    private int $signCount = 0;

    /** The reachability check a real service needs; in-memory it is always up. */
    public static function serviceReachable(): bool
    {
        return true;
    }

    public function sign(string $agent, ?string $version): void
    {
        // Every signing applies an AI-generated manifest and appends to the chain.
        // The model tracks only signCount, so agent/version are not stored here (R6).
        $this->signCount++;
    }

    public function read(): Report
    {
        if ($this->signCount === 0) {
            return Report::absent();
        }

        return new Report(
            hasManifest: true,
            isAiGenerated: true,
            digitalSourceTypes: [self::TRAINED],
            isSignatureValid: true,
        );
    }
}
