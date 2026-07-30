<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Examples\ImmutableBuilder;

use RuntimeException;

/**
 * Thrown by ImmutableBuilder::build() when the required software agent name is
 * blank. The model predicts exactly this via canBuild(); the postcondition asserts
 * the exception is the one the model expected.
 */
final class BlankSoftwareAgentException extends RuntimeException {}
