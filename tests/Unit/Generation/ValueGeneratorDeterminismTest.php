<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;

/**
 * SPEC-010 AC12 — the same seed reproduces the same values and the same shrink sequences.
 *
 * Cross-process reproducibility is proven the way SPEC-003 AC1 proved it: not by spawning a
 * second process, but by pinning literal expected values. Every run of this suite is a fresh
 * process, on whatever machine and PHP version CI happens to use, and each of them has to
 * reproduce these exact figures. What a same-process comparison alone would show is only that
 * two sources agree with each other.
 *
 * The third test is the one that covers the criterion's real content — "nothing here may depend
 * on hash order, spl_object_id, or the clock" — by reading the source rather than trusting it.
 *
 * @return array<string, Generator<mixed>>
 */
function ac12Generators(): array
{
    return [
        'oneOf' => Gen::oneOf([Gen::integers(0, 9), Gen::elements(['a', 'b', 'c'])]),
        'floats' => Gen::floats(-1.5, 1.5),
        'strings' => Gen::strings(0, 5, ['a', 'b', 'c']),
        'listsOf' => Gen::listsOf(Gen::integers(0, 20), 1, 4),
        'subsetOf' => Gen::subsetOf(['a', 'b', 'c'], 1, 3),
        'associative' => Gen::associative(['a' => Gen::integers(0, 9)], optional: ['b' => Gen::integers(0, 9)]),
    ];
}

/**
 * The value, then every candidate of every shrink step along the greedy path — the whole
 * observable behaviour of a generator for one seed, not just its first output.
 *
 * @param  Generator<mixed>  $g
 * @return list<mixed>
 */
function traceOf(Generator $g, int $seed): array
{
    $value = $g->generate(Source::seeded($seed));
    $trace = [$value->value];

    $steps = 0;
    while ($steps < 32) {
        $next = null;
        foreach ($g->shrink($value) as $candidate) {
            $trace[] = $candidate->value;
            $next ??= $candidate;
        }

        if ($next === null) {
            break;
        }

        $value = $next;
        $steps++;
    }

    return $trace;
}

it('reproduces the same values and the same shrink sequences from the same seed', function () {
    foreach (ac12Generators() as $name => $g) {
        foreach (range(1, 10) as $seed) {
            expect(traceOf($g, $seed))->toBe(traceOf($g, $seed), $name);
        }
    }
})->group('SPEC-010');

it('pins each generator against silent drift, in whatever process runs it', function () {
    $traces = [];
    foreach (ac12Generators() as $name => $g) {
        $traces[$name] = traceOf($g, 20260827);
    }

    // Golden values. If a generator's draw order, candidate order or shrink model changes, these
    // change with it — which is the point: every recorded seed in the world depends on them, so
    // the change must be a decision, not a side effect.
    expect($traces)->toBe([
        'oneOf' => ['c', 'a', 'b'],
        'floats' => [0.2716943806101617, 0.0, 0.13584719030508086, 0.20377078545762128, 0.2377325830338915, 0.2547134818220266, 0.26320393121609414, 0.26744915591312796, 0.2695717682616448, 0.2706330744359033, 0.27116372752303247, 0.2714290540665971, 0.2715617173383794, 0.27162804897427056, 0.2716612147922161, 0.27167779770118894, 0.2716860891556753, 0.2716902348829185, 0.27169230774654013, 0.2716933441783509, 0.2716938623942563, 0.271694121502209, 0.2716942510561854, 0.27169431583317355, 0.2716943482216676, 0.27169436441591466, 0.2716943725130382, 0.27169437656159995, 0.27169437858588086, 0.2716943795980213, 0.27169438010409147, 0.2716943803571266, 0.27169438048364414, 0.2716943805469029],
        'strings' => ['c', '', 'a', 'b'],
        'listsOf' => [[17, 17], [17], [0, 17], [9, 17], [13, 17], [15, 17], [16, 17], [17, 0], [17, 9], [17, 13], [17, 15], [17, 16], [0], [9], [13], [15], [16]],
        'subsetOf' => [['c', 'a'], ['c'], ['b', 'a'], ['a'], ['b']],
        'associative' => [['a' => 1, 'b' => 5], ['a' => 0, 'b' => 5], ['a' => 1], ['a' => 1, 'b' => 0], ['a' => 1, 'b' => 3], ['a' => 1, 'b' => 4], ['a' => 0], ['a' => 0, 'b' => 0], ['a' => 0, 'b' => 3], ['a' => 0, 'b' => 4]],
    ]);
})->group('SPEC-010');

it('reads nothing from the clock, the process or object identity', function () {
    $files = [
        'FloatsGenerator', 'StringsGenerator', 'ListsGenerator',
        'SubsetGenerator', 'OneOfGenerator', 'AssociativeGenerator',
    ];

    // A generator that reached for any of these would still pass a same-process comparison and
    // would still look reproducible in a single run — and would quietly make every recorded seed
    // worthless. Reading the source is the cheap way to know it did not.
    $forbidden = '/\b(spl_object_id|spl_object_hash|time|microtime|hrtime|date|uniqid|mt_rand|rand|random_int|random_bytes|array_rand|shuffle|str_shuffle)\s*\(/';

    $offenders = [];
    foreach ($files as $class) {
        $path = dirname(__DIR__, 3)."/src/Generation/$class.php";
        $source = (string) file_get_contents($path);

        if (preg_match($forbidden, $source) === 1) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
})->group('SPEC-010', 'arch');
