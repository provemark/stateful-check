<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Examples\ImmutableBuilder\BuilderModel;
use Provemark\StatefulCheck\Examples\ImmutableBuilder\Format;
use Provemark\StatefulCheck\Examples\ImmutableBuilder\ImmutableBuilder;
use Provemark\StatefulCheck\Examples\ImmutableBuilder\WithClaimGenerator;
use Provemark\StatefulCheck\Examples\ImmutableBuilder\WithSoftwareAgent;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Ref;
use Provemark\StatefulCheck\Setup;
use Provemark\StatefulCheck\StatefulProperty;

/**
 * Dogfood example 1 — the immutable builder.
 *
 * Ported from provemark/content-credentials'
 * tests/Unit/Property/BuilderSequencePropertyTest.php — specifically its one
 * genuinely stateful property, "matches the shadow model after every step". The
 * other three properties in that file are not stateful and are out of scope here
 * (D013): a stateful entry point cannot express a pairwise commutativity law, and
 * it structurally cannot express the immutability invariant (README limitations).
 *
 * The system under test is a self-contained stand-in (D014); BuilderModel and the
 * property structure are the original. This file is a design artefact for the API
 * (ROADMAP step 1): it is red until the package is implemented, and it runs under
 * `composer examples`, never in the default suite.
 *
 * The bug class hunted: accumulation order — a with* that clobbers an unrelated
 * slot, or output that depends on the path taken rather than on the last write of
 * each kind. The model predicts the blank-name error boundary; the runner checks
 * after every step, including whether building is possible at all.
 */
it('matches the shadow model after every step of any with* sequence', function () {
    // Whole values drawn with elements — the blanks (which build() must reject) sit
    // in the same set, so no oneOf is needed (SPEC-003 combinator audit; see NOTES).
    // Valid names first, blanks last is deliberate: elements shrinks toward the first
    // element, so a counterexample reduces toward a valid name and keeps a blank only
    // if the bug needs it. Moot here — this property passes, so the shrinker never runs.
    $name = Gen::elements(['ACME GenAI', 'agent-2', 'Content Credentials', '', '   ', "\t"]);

    $version = Gen::elements([null, '1.0', '2.3.1']);

    // Named keys via associative — no positional tuple indexing (SPEC-003; see NOTES).
    $args = Gen::associative(['name' => $name, 'version' => $version]);

    $result = (new StatefulProperty(
        alphabet: [
            Gen::map(fn (array $a) => new WithSoftwareAgent($a['name'], $a['version']), $args),
            Gen::map(fn (array $a) => new WithClaimGenerator($a['name'], $a['version']), $args),
        ],
        initial: Gen::elements(Format::cases()),
        setup: fn (Format $format) => new Setup(
            model: BuilderModel::initial($format),
            system: new Ref(ImmutableBuilder::for($format)),
        ),
    ))->check();

    expect($result->passed)->toBeTrue($result->counterexampleAsString());
})->group('example');
