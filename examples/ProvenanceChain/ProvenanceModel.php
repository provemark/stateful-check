<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ProvenanceChain;

/**
 * Shadow model of an asset's provenance chain.
 *
 * Ported verbatim from the hand-rolled integration suite in
 * provemark/content-credentials: the real state lives in the session; this is the
 * simple, obviously-correct account of it. It tracks only what a read can observe
 * (R6) — signing appends, and an asset carries a manifest and the Article 50 AI
 * marking exactly once it has been signed at least once.
 */
final readonly class ProvenanceModel
{
    public function __construct(
        public MediaType $mediaType,
        public int $signCount = 0,
    ) {}

    public static function unsigned(MediaType $mediaType): self
    {
        return new self($mediaType);
    }

    public function afterSign(): self
    {
        return new self($this->mediaType, $this->signCount + 1);
    }

    /** An asset carries a manifest exactly once it has been signed at least once. */
    public function expectsManifest(): bool
    {
        return $this->signCount > 0;
    }

    /**
     * The AI marking must be present once signed, and STAY present however many
     * further signings happen. This is the invariant the whole library upholds.
     */
    public function expectsAiMarking(): bool
    {
        return $this->signCount > 0;
    }
}
