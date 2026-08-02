<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Testing;

use PHPUnit\Framework\Assert;
use Provemark\StatefulCheck\PropertyResult;

/**
 * SPEC-007 — the optional typed pass-assertion helper.
 *
 * A namespaced free function in a file that is deliberately NOT PSR-4 autoloaded:
 * nothing in the package's autoloaded runtime references PHPUnit, so `require` stays
 * PHP-only (R7 / AC3). A consumer registers it with one require_once from their test
 * bootstrap; the package's own suite does the same via tests/Pest.php.
 *
 * On failure the message is exactly the rendered reproduction artefact — seed,
 * initial and counterexample — so the seed cannot be dropped (the footgun this spec
 * removes). Assert::fail sets that message verbatim; Assert::assertTrue would append
 * PHPUnit's own "Failed asserting that false is true." and bury the artefact.
 *
 * The helper reads only `passed` and `counterexampleAsString()`, neither of which
 * depends on the model, system or initial type. It is generic in all three so it
 * accepts a PropertyResult at any instantiation: PropertyResult's templates are
 * invariant, so a bare `<mixed, mixed, mixed>` parameter would reject a concrete
 * result (e.g. the `<…, null>` a passing run produces).
 *
 * @template TModel
 * @template TSut
 * @template TInitial
 *
 * @param  PropertyResult<TModel, TSut, TInitial>  $result
 */
function assertPropertyPassed(PropertyResult $result): void
{
    if (! $result->passed) {
        Assert::fail($result->counterexampleAsString());
    }

    // The pass path still records an assertion, so the test is not flagged risky for
    // asserting nothing (phpunit.xml failOnRisky).
    Assert::assertTrue($result->passed);
}
