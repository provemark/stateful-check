<?php

declare(strict_types=1);

use Provemark\StatefulCheck\PropertyResult;

use function Provemark\StatefulCheck\Testing\assertPropertyPassed;

/**
 * SPEC-007 — the optional typed assertPropertyPassed() helper.
 *
 * The result is constructed directly rather than driven through a real
 * StatefulProperty: AC1 is about the helper, not the engine, so isolating it keeps
 * the test deterministic and free of SPEC-005 coupling.
 */
it('asserts cleanly on a passing property result (SPEC-007 AC1)', function () {
    $result = new PropertyResult(passed: true, seed: 123);

    // A passing result must not raise; assertPropertyPassed records the passing
    // assertion itself (Assert::assertTrue), so the test needs no further expect().
    assertPropertyPassed($result);
})->group('SPEC-007');
