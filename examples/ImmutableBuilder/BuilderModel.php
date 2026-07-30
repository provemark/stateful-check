<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ImmutableBuilder;

/**
 * The shadow model — the oracle the builder is checked against after every command.
 *
 * Ported verbatim from the hand-rolled suite in provemark/content-credentials:
 * two nullable slots, last-write-wins, and a canBuild() that mirrors the builder's
 * "a software agent is required and must be non-blank" contract. Being obviously
 * correct by inspection is the whole point (R6). Only expectedToArray() is
 * simplified to match this example's stand-in builder rather than the real C2PA
 * output — the model logic that matters is unchanged (D014, and see NOTES).
 *
 * @phpstan-type Entry array{name: string, version: string|null}
 */
final readonly class BuilderModel
{
    /**
     * @param  Entry|null  $softwareAgent
     * @param  Entry|null  $claimGenerator
     */
    private function __construct(
        public Format $format,
        public ?array $softwareAgent = null,
        public ?array $claimGenerator = null,
    ) {}

    public static function initial(Format $format): self
    {
        return new self($format);
    }

    public function withSoftwareAgent(string $name, ?string $version): self
    {
        return new self($this->format, ['name' => $name, 'version' => $version], $this->claimGenerator);
    }

    public function withClaimGenerator(string $name, ?string $version): self
    {
        return new self($this->format, $this->softwareAgent, ['name' => $name, 'version' => $version]);
    }

    /** Mirrors the builder's contract: a software agent is required and must be non-blank. */
    public function canBuild(): bool
    {
        return $this->softwareAgent !== null && trim($this->softwareAgent['name']) !== '';
    }

    /**
     * What build() must return in this state. Only consulted when canBuild() is
     * true, so the software agent is always present here.
     *
     * @return array<string, mixed>
     */
    public function expectedToArray(): array
    {
        $manifest = [
            'format' => $this->format->value,
            'softwareAgent' => self::entry($this->softwareAgent ?? ['name' => '', 'version' => null]),
        ];

        if ($this->claimGenerator !== null) {
            $manifest['claimGenerator'] = self::entry($this->claimGenerator);
        }

        return $manifest;
    }

    /**
     * @param  Entry  $entry
     * @return array{name: string, version?: string}
     */
    private static function entry(array $entry): array
    {
        return $entry['version'] === null
            ? ['name' => $entry['name']]
            : ['name' => $entry['name'], 'version' => $entry['version']];
    }
}
