<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ImmutableBuilder;

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\Ref;

/**
 * Set the claim generator, then build.
 *
 * A blank claim generator name does not make build() throw — only the software
 * agent is required — so the postcondition keys off the model's canBuild(), which
 * depends only on the software agent. When the agent is still blank, build() throws
 * and the model already predicts it.
 *
 * @implements Command<BuilderModel, Ref<ImmutableBuilder>, array<string, mixed>>
 */
final readonly class WithClaimGenerator implements Command
{
    public function __construct(
        private string $name,
        private ?string $version,
    ) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->value = $sut->value->withClaimGenerator($this->name, $this->version);

        return $sut->value->build(); // throws when the software agent is still blank
    }

    public function nextState(mixed $model): mixed
    {
        return $model->withClaimGenerator($this->name, $this->version);
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $model->canBuild()
            ? ! $outcome->threw && $outcome->value === $model->expectedToArray()
            : $outcome->threw && $outcome->exception instanceof BlankSoftwareAgentException;
    }

    public function __toString(): string
    {
        return sprintf('claimGenerator(%s, %s)', var_export($this->name, true), var_export($this->version, true));
    }
}
