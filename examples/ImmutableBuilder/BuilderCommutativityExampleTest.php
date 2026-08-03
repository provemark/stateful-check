<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Examples\ImmutableBuilder\Format;
use Provemark\StatefulCheck\Examples\ImmutableBuilder\ImmutableBuilder;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\StatelessProperty;

/**
 * Dogfood example 3 — the immutable builder's commutativity law, stateless.
 *
 * This is one of the non-stateful properties from provemark/content-credentials'
 * BuilderSequencePropertyTest.php that the stateful entry point could not express
 * (see ImmutableBuilderExampleTest's note, and D013): two *independent* setters
 * commute — setting the software agent then the claim generator builds the identical
 * manifest as the reverse order. It is a `forAll` over one drawn record, exactly what
 * StatelessProperty (SPEC-008) exists for — no command sequence, no model. The system
 * under test is the same self-contained builder (D014).
 *
 * A design artefact for the stateless API (§5): it runs under `composer examples`,
 * never in the default suite, and is written to pass — porting it is the honest test
 * of whether StatelessProperty earns its place. It is the stateless counterpart of
 * example 1's stateful "matches the model after every step".
 */
it('builds the identical manifest whichever order two independent setters are applied', function () {
    // Non-blank agent names only: build() requires a software agent, and this law is about the built
    // output, not the blank-name error boundary (that is example 1's job). The claim generator's name
    // is unconstrained — build() does not require it.
    $args = Gen::associative([
        'format' => Gen::elements(Format::cases()),
        'agentName' => Gen::elements(['ACME GenAI', 'agent-2', 'Content Credentials']),
        'agentVersion' => Gen::elements([null, '1.0', '2.3.1']),
        'claimName' => Gen::elements(['claim-a', 'Content Credentials', 'gen-x']),
        'claimVersion' => Gen::elements([null, '9.9']),
    ]);

    $result = (new StatelessProperty(
        generator: $args,
        predicate: function (array $v): bool {
            $agentFirst = ImmutableBuilder::for($v['format'])
                ->withSoftwareAgent($v['agentName'], $v['agentVersion'])
                ->withClaimGenerator($v['claimName'], $v['claimVersion'])
                ->build();

            $claimFirst = ImmutableBuilder::for($v['format'])
                ->withClaimGenerator($v['claimName'], $v['claimVersion'])
                ->withSoftwareAgent($v['agentName'], $v['agentVersion'])
                ->build();

            // Strict equality is order-sensitive on array keys; it holds only because build() lays the
            // manifest out in a fixed key order, independent of the setter call order — which is the law.
            return $agentFirst === $claimFirst;
        },
    ))->check();

    expect($result->passed)->toBeTrue($result->counterexampleAsString());
})->group('example');
