<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ProvenanceChain;

/**
 * What a read of the asset observes.
 *
 * A readonly value, so two reads of an unchanged asset compare equal with `==` —
 * which is exactly what the Read command's postcondition asserts. Deliberately only
 * the fields the model can predict (R6): presence of a manifest, the AI marking, its
 * digital source types, and signature validity.
 */
final readonly class Report
{
    /** @param  list<string>  $digitalSourceTypes */
    public function __construct(
        public bool $hasManifest,
        public bool $isAiGenerated,
        public array $digitalSourceTypes,
        public bool $isSignatureValid,
    ) {}

    public static function absent(): self
    {
        return new self(false, false, [], false);
    }
}
