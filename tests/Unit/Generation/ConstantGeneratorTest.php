<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-003 AC3 (degenerate edge) — constant() generates one value and never shrinks.
 */
it('constant() generates its value regardless of the source and does not shrink', function () {
    $g = Gen::constant('x');

    expect($g->generate(Source::seeded(1))->value)->toBe('x')
        ->and($g->generate(Source::seeded(999))->value)->toBe('x')
        ->and([...$g->shrink(new GeneratedValue('x'))])->toBe([]);
})->group('SPEC-003');
