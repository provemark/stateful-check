<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Failure;
use Provemark\StatefulCheck\FailureKind;

/**
 * SPEC-002 AC10 — Failure::sameKindAs() is the identity comparison AC1 rests on: two failures are
 * the same kind iff FailureKind, failing command class, and exception class are equal, the exception
 * class by EXACT class (D020). `index` is ignored. Deferred from SPEC-001, built with its consumer.
 */
it('is the same kind when kind, command class, and exception class all match', function () {
    $a = new Failure(FailureKind::UnexpectedException, 3, 'App\\Withdraw', RuntimeException::class);
    $b = new Failure(FailureKind::UnexpectedException, 7, 'App\\Withdraw', RuntimeException::class);

    // index differs (3 vs 7) but is ignored.
    expect($a->sameKindAs($b))->toBeTrue();
})->group('SPEC-002');

it('is a different kind when the FailureKind differs', function () {
    $a = new Failure(FailureKind::PostconditionFalse, 1, 'App\\Withdraw');
    $b = new Failure(FailureKind::UnexpectedException, 1, 'App\\Withdraw');

    expect($a->sameKindAs($b))->toBeFalse();
})->group('SPEC-002');

it('is a different kind when the command class differs', function () {
    $a = new Failure(FailureKind::PostconditionFalse, 1, 'App\\Withdraw');
    $b = new Failure(FailureKind::PostconditionFalse, 1, 'App\\Deposit');

    expect($a->sameKindAs($b))->toBeFalse();
})->group('SPEC-002');

it('compares the exception class by exact class: a subclass is a different kind', function () {
    // DomainException extends LogicException, so an instanceof-style comparison would call these the
    // same. Exact-class equality (D020) does not — that is the whole point of storing the concrete
    // class, so a system throwing subclass exceptions of one defect is not mistaken for another.
    $a = new Failure(FailureKind::UnexpectedException, 1, 'App\\Withdraw', DomainException::class);
    $b = new Failure(FailureKind::UnexpectedException, 1, 'App\\Withdraw', LogicException::class);

    expect($a->sameKindAs($b))->toBeFalse();
})->group('SPEC-002');

it('treats two returned-path failures (no exception) as the same kind', function () {
    $a = new Failure(FailureKind::PostconditionFalse, 2, 'App\\Withdraw');
    $b = new Failure(FailureKind::PostconditionFalse, 9, 'App\\Withdraw');

    expect($a->sameKindAs($b))->toBeTrue();
})->group('SPEC-002');

it('is a different kind when one failure threw and the other did not', function () {
    $threw = new Failure(FailureKind::UnexpectedException, 1, 'App\\Withdraw', RuntimeException::class);
    $returned = new Failure(FailureKind::UnexpectedException, 1, 'App\\Withdraw');

    expect($threw->sameKindAs($returned))->toBeFalse();
})->group('SPEC-002');
