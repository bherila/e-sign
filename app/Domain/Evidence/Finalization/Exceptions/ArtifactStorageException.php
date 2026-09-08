<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Exceptions;

/**
 * Raised when artifact bytes could not be stored, or could not be read back as what was
 * written.
 *
 * A storage adapter reporting a successful write is not evidence that the bytes are
 * retrievable, which is why this is a distinct failure from a rendering or sealing one: it
 * is the case docs/ARCHITECTURE.md step 2 exists to catch.
 */
final class ArtifactStorageException extends FinalizationException {}
