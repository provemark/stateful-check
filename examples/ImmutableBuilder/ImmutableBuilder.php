<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ImmutableBuilder;

/**
 * A small, self-contained stand-in for an immutable builder (D014).
 *
 * The same *shape* as the real ManifestBuilder it stands in for — with* methods
 * that return a new instance, last-write-wins accumulation, and a required
 * software agent whose blank name makes build() throw — but none of the C2PA
 * domain detail, which would be noise to a reader here. Everything this example
 * needs lives in this directory; it depends on no other package (D014).
 */
final readonly class ImmutableBuilder
{
    /**
     * @param  array{name: string, version: string|null}|null  $softwareAgent
     * @param  array{name: string, version: string|null}|null  $claimGenerator
     */
    private function __construct(
        private Format $format,
        private ?array $softwareAgent = null,
        private ?array $claimGenerator = null,
    ) {}

    public static function for(Format $format): self
    {
        return new self($format);
    }

    public function withSoftwareAgent(string $name, ?string $version = null): self
    {
        return new self($this->format, ['name' => $name, 'version' => $version], $this->claimGenerator);
    }

    public function withClaimGenerator(string $name, ?string $version = null): self
    {
        return new self($this->format, $this->softwareAgent, ['name' => $name, 'version' => $version]);
    }

    /**
     * The built manifest as a plain array. Deliberately flat — the fidelity that
     * matters is in the model, not in this shape (see NOTES).
     *
     * @return array<string, mixed>
     *
     * @throws BlankSoftwareAgentException when the required software agent is absent or blank
     */
    public function build(): array
    {
        if ($this->softwareAgent === null || trim($this->softwareAgent['name']) === '') {
            throw new BlankSoftwareAgentException('A non-blank software agent is required.');
        }

        $manifest = [
            'format' => $this->format->value,
            'softwareAgent' => self::entry($this->softwareAgent),
        ];

        if ($this->claimGenerator !== null) {
            $manifest['claimGenerator'] = self::entry($this->claimGenerator);
        }

        return $manifest;
    }

    /**
     * @param  array{name: string, version: string|null}  $entry
     * @return array{name: string, version?: string}
     */
    private static function entry(array $entry): array
    {
        return $entry['version'] === null
            ? ['name' => $entry['name']]
            : ['name' => $entry['name'], 'version' => $entry['version']];
    }
}
