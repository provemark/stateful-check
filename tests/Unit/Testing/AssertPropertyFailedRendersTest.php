<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Provemark\StatefulCheck\PropertyResult;

use function Provemark\StatefulCheck\Testing\assertPropertyPassed;

/**
 * SPEC-007 AC2 — a failing result must fail the test with the reproduction artefact
 * as the message, so the seed can never be dropped (the footgun this spec removes).
 *
 * The message is asserted to equal counterexampleAsString() exactly: that binding
 * is the whole point of the helper, so the test compares against the method's own
 * output rather than a hand-copied string.
 */
it('fails the test with counterexampleAsString() as the message (SPEC-007 AC2)', function () {
    $result = new PropertyResult(passed: false, seed: 123);

    try {
        assertPropertyPassed($result);
        $this->fail('assertPropertyPassed() did not fail a non-passing result.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toBe($result->counterexampleAsString());
    }
})->group('SPEC-007');
