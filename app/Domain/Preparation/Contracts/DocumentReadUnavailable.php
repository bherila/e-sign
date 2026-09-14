<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Contracts;

use App\Domain\Preparation\Isolation\DocumentIsolationUnavailable;
use RuntimeException;
use Throwable;

/**
 * A document read failed on the service side, so nothing about the document was learned.
 *
 * Declared by every document-read port — {@see PdfPreflight}, {@see PdfAssembler} and
 * {@see PdfTextLocator} — beside the failure each promises for a document it read and could not
 * accept. The two answer different people: an unreadable document is the sender's to fix, and this
 * is the deployment's. Reported as the other, it stored valid uploads as `preflight_failed`, failed
 * finalizations a retry would have completed, and told integrations to correct requests that needed
 * no correcting (docs/adr/0006, issue #119).
 *
 * Raised when a read's child process could not be started, exited unexpectedly or answered with
 * something that could not be decoded; and, as {@see DocumentIsolationUnavailable},
 * when process isolation is required and this host cannot provide it.
 *
 * `getMessage()` is for the log, and can name the host's PHP binary or the child's exit status.
 * {@see publicMessage()} is the sentence a caller is shown.
 */
class DocumentReadUnavailable extends RuntimeException
{
    public static function childFailed(Throwable $previous): self
    {
        return new self('The document read failed on the service side: '.$previous->getMessage(), previous: $previous);
    }

    /**
     * Whether the same read may succeed when simply tried again, with nothing on the host changed.
     *
     * True for a child that failed once. False for isolation that is unavailable, which is resolved
     * once per PHP process and stays that way until the host is fixed.
     */
    public function isTransient(): bool
    {
        return true;
    }

    public function publicMessage(): string
    {
        return 'The document could not be read because of a failure on the service side. Nothing about the '
            .'document or the request needs to change, and the same call may succeed on a retry.';
    }

    public function code(): string
    {
        return 'document_unavailable';
    }
}
