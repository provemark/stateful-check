<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
|
| Groups in use:
|   SPEC-###  every acceptance criterion of that spec
|   meta      shrinker correctness against deliberately planted bugs (CLAUDE.md R8)
|   arch      architecture rules enforced as tests
|
*/

// Register the package's optional assertPropertyPassed() helper (SPEC-007) — the
// same require a consumer adds to their own bootstrap. Kept out of src/ so the
// runtime autoload path never references PHPUnit (R7).
require_once __DIR__.'/../testing/assertions.php';
