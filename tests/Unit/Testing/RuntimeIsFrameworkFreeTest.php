<?php

declare(strict_types=1);

/**
 * SPEC-007 AC3 / R7 — the package's autoloaded runtime pulls in no test framework.
 *
 * `require` stays PHP-only: nothing under src/ (the PSR-4 autoload root) imports
 * PHPUnit or Pest, so loading the package never drags a test framework in. The
 * assertion helper is deliberately outside src/ — a free function in testing/,
 * reachable only by an explicit require — so it falls outside this scan by
 * construction, which is exactly why autoloading the package cannot pull PHPUnit in.
 *
 * A recursive import scan rather than Pest's arch(): arch's
 * `expect('Provemark\StatefulCheck')` does not recurse into sub-namespaces
 * (Generation, Shrinking), so it silently covered almost none of the runtime and
 * could not be shown to have teeth. This scan covers every src/**.php and its teeth
 * are proven by a planted-import meta-check. It catches `use` imports — the way a
 * dependency realistically enters a file — not bare inline FQNs.
 */
it('imports no PHPUnit or Pest symbol anywhere under src/ (SPEC-007 AC3 / R7)', function () {
    $src = dirname(__DIR__, 3).'/src';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
    );

    $offenders = [];
    foreach ($files as $file) {
        // RecursiveIteratorIterator yields mixed to PHPStan; narrow instead of a
        // banned inline @var.
        if (! $file instanceof SplFileInfo) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/\buse\s+(?:PHPUnit|Pest)\\\\/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
})->group('SPEC-007', 'arch');
