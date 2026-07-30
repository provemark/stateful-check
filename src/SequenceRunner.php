<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck;

use Throwable;

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
            if (! $command->preCondition($model)) {
                // Precondition false: skip. run()/nextState() never run, the model does not
                // advance, and the run does not fail (AC3). This is what keeps R1 sound — a
                // shortened sequence cannot be ill-formed, because commands that no longer
                // apply are simply skipped. The false position is kept so `executed` stays
                // index-aligned with $commands.
                $executed[] = false;

                continue;
            }

            try {
                $outcome = Outcome::returned($command->run($sut));
            } catch (Throwable $e) {
                // Catch Throwable, not only Exception: a TypeError, a call on null, a division
                // by zero in the system under test is a real bug this tool exists to find, so it
                // is wrapped into the Outcome for the postcondition to judge and (AC6) the
                // shrinker to minimise, rather than crashing the run. The deliberate cost is that
                // a genuine infrastructure Error is also treated as a finding — an unexpected
                // throw of any kind is a test failure. `Outcome::threw` already types this Throwable.
                $outcome = Outcome::threw($e);
            }

            // nextState runs whether or not run() threw: the transition is pure and predicts the
            // model's next state independently of what actually happened (R6).
            $modelBefore = $model;
            $model = $command->nextState($model);
            $executed[] = true;

            if (! $command->postCondition($model, $sut, $outcome)) {
                // run() returned normally, so by the precedence rule (AC2) the kind is
                // PostconditionFalse; the throw path (UnexpectedException) is AC6.
                $failure = new Failure(FailureKind::PostconditionFalse, count($executed) - 1, $command::class);

                // Commands after the stop never ran.
                while (count($executed) < count($commands)) {
                    $executed[] = false;
                }

                return new RunResult(false, $executed, $failure, $modelBefore, $model);
            }
        }

        return new RunResult(true, $executed);
    }
}
