<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

use RuntimeException;

/**
 * The bytes did not make it to the disk intact.
 *
 * Raised by DocumentBlobStore when a write fails, when the object cannot be read back, or
 * when what comes back does not hash to the digest that was written. Intake lets it
 * propagate: no document row is created, so the database never claims to hold a document
 * whose bytes are absent or corrupt. Objects already written are unreferenced and are
 * reclaimed by the orphan pruner described in docs/BLOB_STORAGE.md.
 */
final class DocumentStorageException extends RuntimeException {}
