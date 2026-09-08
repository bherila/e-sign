<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

/**
 * Where an uploaded document stands.
 *
 * There is no "processing" state: intake is synchronous, and a document row only exists
 * once its bytes are on disk and have been read back and verified. A caller therefore
 * never sees a document whose storage is still in flight.
 */
enum DocumentStatus: string
{
    /**
     * The original is stored and verified; no review revision has been recorded yet.
     *
     * Every document is created in this state and leaves it in the same transaction, so a
     * row resting here means the revision write is missing. It is not a resting state and
     * such a document must never be shown for assent.
     */
    case Uploaded = 'uploaded';

    /**
     * Preflight rejected the document. The original bytes are retained for forensics; the
     * report says why. No review revision exists and none will: the document is terminal.
     */
    case PreflightFailed = 'preflight_failed';

    /** Original retained, review revision recorded. The document can be prepared. */
    case Ready = 'ready';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
