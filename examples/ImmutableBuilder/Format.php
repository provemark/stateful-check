<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ImmutableBuilder;

/**
 * The initial state for a sequence: which output format the builder starts from.
 * Drawn once per sequence and threaded into both model and system through the
 * setup (SPEC-005 D012). A stand-in for the real suite's MediaType.
 */
enum Format: string
{
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';
}
