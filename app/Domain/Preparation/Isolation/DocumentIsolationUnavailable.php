<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use RuntimeException;

/**
 * Process isolation is required (`esign.documents.isolation.mode=process`) and this host cannot
 * provide it.
 *
 * A server configuration failure, not a statement about any document: it is thrown rather than
 * reported as a rejection, so a sender is never told to fix a file that is fine.
 */
final class DocumentIsolationUnavailable extends RuntimeException {}
