<?php

declare(strict_types=1);
use Random\Engine\Mt19937;
use Random\Randomizer;

$seed = 424242;

function draw(int $seed, int $n = 8): array
{
    $r = new Randomizer(new Mt19937($seed));
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $r->getInt(0, 1_000_000);
    }

    return $out;
}

$mode = $argv[1] ?? 'all';

if ($mode === 'emit') {
    echo implode(',', draw($seed)), "\n";
    exit;
}

echo 'PHP ', PHP_VERSION, '  (', PHP_OS_FAMILY, ', ', PHP_INT_SIZE * 8, "-bit)\n\n";

// 1. determinism within one process
$a = draw($seed);
$b = draw($seed);
echo '1. same seed, same process      : ', ($a === $b ? 'IDENTICAL' : 'DIFFERENT'), "\n";
echo '   values: ', implode(',', array_slice($a, 0, 5)), "...\n";

// 2. isolation: two randomizers do not interfere
$r1 = new Randomizer(new Mt19937($seed));
$r2 = new Randomizer(new Mt19937($seed));
$i1 = [];
$i2 = [];
for ($i = 0; $i < 6; $i++) {
    $i1[] = $r1->getInt(0, 999);
    $i2[] = $r2->getInt(0, 999);
}
echo '2. two instances, no interference: ', ($i1 === $i2 ? 'ISOLATED' : 'LEAKY'), "\n";

// 3. does the global mt_srand() affect an engine object?
mt_srand(1);
$g1 = (new Randomizer(new Mt19937($seed)))->getInt(0, 999);
mt_srand(2);
$g2 = (new Randomizer(new Mt19937($seed)))->getInt(0, 999);
echo '3. immune to global mt_srand()   : ', ($g1 === $g2 ? 'YES' : 'NO'), "\n";

// 4. serialisable state (capture / restore)
$r = new Randomizer(new Mt19937($seed));
$r->getInt(0, 999);
$r->getInt(0, 999);
$frozen = serialize($r);
$next = $r->getInt(0, 999);
$restored = unserialize($frozen);
echo '4. serialise + restore resumes   : ', ($restored->getInt(0, 999) === $next ? 'YES' : 'NO'), "\n";

// 5. does clone give an independent continuation? (fork semantics, for the record)
$r = new Randomizer(new Mt19937($seed));
$r->getInt(0, 999);
$c = clone $r;
$fromOriginal = $r->getInt(0, 999);
$fromClone = $c->getInt(0, 999);
echo '5. clone continues independently : ', ($fromOriginal === $fromClone ? 'YES (same stream)' : 'NO (diverged)'), "\n";

// 6. MT_RAND_PHP vs default mode
$std = (new Randomizer(new Mt19937($seed, MT_RAND_MT19937)))->getInt(0, 999);
$php = (new Randomizer(new Mt19937($seed, MT_RAND_PHP)))->getInt(0, 999);
echo '6. MT_RAND_PHP differs from std  : ', ($std !== $php ? 'YES — pin the mode explicitly' : 'no'), "\n";
