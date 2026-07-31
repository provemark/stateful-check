<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Examples\ProvenanceChain\MediaType;
use Provemark\StatefulCheck\Examples\ProvenanceChain\ProvenanceModel;
use Provemark\StatefulCheck\Examples\ProvenanceChain\ProvenanceSession;
use Provemark\StatefulCheck\Examples\ProvenanceChain\Read;
use Provemark\StatefulCheck\Examples\ProvenanceChain\Sign;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Setup;
use Provemark\StatefulCheck\StatefulProperty;

/**
 * Dogfood example 2 — the provenance chain.
 *
 * Ported from provemark/content-credentials'
 * tests/Integration/Property/ProvenanceChainPropertyTest.php. The mutable system
 * under test is a self-contained in-memory stand-in (D014); ProvenanceModel is the
 * original. This file is a design artefact for the API (ROADMAP step 1): red until
 * the package is implemented, run under `composer examples`, never in the default
 * suite. It is the mutable-SUT counterpart to example 1's immutable Ref.
 *
 * Read profile — the cost the D011 discipline is about, over a real service:
 *   - Sign: one read in its postcondition (verify the chain), on top of the sign.
 *   - Read: two reads (one in run(), one in the postcondition idempotency check).
 * The original capped chains hard because each command was an HTTP round-trip; the
 * runs and maxLength here echo that, though in memory the reads are free.
 */
it('keeps the AI marking intact across any chain of signings and reads', function () {
    $agent = Gen::elements(['ACME GenAI Image Model', 'Model é漢', 'agent-2', 'x', 'Stable Something 1.5']);
    $version = Gen::elements([null, '1.0.0', '3.1.0']);

    $sign = Gen::map(
        fn (array $a) => new Sign($a['agent'], $a['version']),
        Gen::associative(['agent' => $agent, 'version' => $version]),
    );
    $read = Gen::constant(new Read);

    $result = (new StatefulProperty(
        // Idiom: to weight the alphabet toward signing, list Sign twice. The
        // command-alphabet generator picks uniformly with no built-in bias — as
        // fast-check does deliberately — so duplication is the intended way to weight.
        alphabet: [$sign, $sign, $read],
        // The setup takes the drawn initial value even though it is null here; `fn (mixed $initial)`
        // rather than `fn ()` makes visible that a value is passed, instead of leaning on PHP silently
        // dropping the argument to a zero-parameter closure.
        setup: fn (mixed $initial) => new Setup(
            model: ProvenanceModel::unsigned(MediaType::Png),
            system: new ProvenanceSession,
        ),
        // No drawn initial state: the model starts unsigned regardless. `initial` is required (the
        // sketched optional default could not type-check, D012 amendment); `Gen::constant(null)` is how
        // a property with no initial state says so.
        initial: Gen::constant(null),
        maxLength: 4,   // a real service caps chain length hard; echo that here
        runs: 25,
    ))->check();

    expect($result->passed)->toBeTrue($result->counterexampleAsString());
})->group('example')
    ->skip(fn () => ! ProvenanceSession::serviceReachable(), 'provenance service not reachable');
