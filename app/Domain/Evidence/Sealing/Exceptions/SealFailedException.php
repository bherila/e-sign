<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Exceptions;

/**
 * The PDF engine could not produce a sealed artifact.
 *
 * Covers an input the engine refuses (encrypted, already signed, structurally
 * broken) and any other failure inside the sealing pipeline.
 */
final class SealFailedException extends SealingException {}
