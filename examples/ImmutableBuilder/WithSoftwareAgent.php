<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ImmutableBuilder;

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\Ref;

/**
 * Set the software agent, then build.
 *
 * The throwing call — build() — is the last thing run() does, so the runner
 * captures it in the Outcome and the pure transition still describes what happened
 * (SPEC-001 design notes; SPEC-005). $sut is the Ref holding the current builder.
 *
 * One `@implements` line types all four methods (D001): $model is a BuilderModel,
 * $sut a Ref<ImmutableBuilder>, and run()'s array flows into the Outcome the
 * postcondition reads. Without the templates each method would need its own @param.
 *
 * @implements Command<BuilderModel, Ref<ImmutableBuilder>, array<string, mixed>>
 */
final readonly class WithSoftwareAgent implements Command
{
    public function __construct(
        private string $name,
        private ?string $version,
    ) {}

    public function preCondition(mixed $model): bool
    {
        return true; // always applicable — the original property is precondition-free
    }

    public function run(mixed $sut): mixed
    {
        $sut->value = $sut->value->withSoftwareAgent($this->name, $this->version);

        return $sut->value->build(); // throws on a blank name; captured into the Outcome
    }

    public function nextState(mixed $model): mixed
    {
        return $model->withSoftwareAgent($this->name, $this->version);
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        // $model is the state AFTER nextState. Observation flows through the Outcome,
        // so $sut is not read here (see NOTES: this example does not exercise AC8/D011).
        return $model->canBuild()
            ? ! $outcome->threw && $outcome->value === $model->expectedToArray()
            : $outcome->threw && $outcome->exception instanceof BlankSoftwareAgentException;
    }

    public function __toString(): string
    {
        return sprintf('softwareAgent(%s, %s)', var_export($this->name, true), var_export($this->version, true));
    }
}
