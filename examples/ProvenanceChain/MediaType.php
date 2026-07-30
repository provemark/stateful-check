<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ProvenanceChain;

/** Media type of the asset under test. Fixed to Png in this example, as the original was. */
enum MediaType: string
{
    case Png = 'image/png';
}
