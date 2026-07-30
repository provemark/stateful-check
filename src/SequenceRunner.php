<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

/**
 * Executes a command sequence against a system and a shadow model (SPEC-001).
 *
 * For AC1 the runner walks a sequence whose commands all agree with the model and reports
 * success. The two boolean methods are invoked but their results are not acted on yet:
 * skipping a command whose precondition is false is AC3, and stopping at a failing
 * postcondition with a structured failure is AC2. They arrive with their own tests.
 */
final class SequenceRunner
{
    /**
     * A sequence runs commands that share one model type and one system type; only their
     * result types vary, which the covariant TResult absorbs (bound to mixed here).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<Command<TModel, TSut, mixed>>  $commands
     * @param  callable(): TSut  $freshSut  produces the system to run against
     * @param  TModel  $initialModel
     */
    public function run(array $commands, callable $freshSut, mixed $initialModel): RunResult
    {
        $sut = $freshSut();
        $model = $initialModel;
        $executed = [];

        foreach ($commands as $command) {
            // Acting on a false precondition (skip the command) is AC3.
            $command->preCondition($model);

            $outcome = Outcome::returned($command->run($sut));
            $model = $command->nextState($model);

            // Acting on a false postcondition (stop with a structured failure) is AC2.
            $command->postCondition($model, $sut, $outcome);

            $executed[] = true;
        }

        return new RunResult(true, $executed);
    }
}
