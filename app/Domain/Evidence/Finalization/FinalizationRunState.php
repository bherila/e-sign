<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

/**
 * How far one finalization attempt got.
 *
 * The states are the four steps of the staged publication in docs/ARCHITECTURE.md, plus the
 * one outcome that is not a step. They exist so a crashed worker leaves behind a fact rather
 * than an ambiguity: `uploaded` means bytes are in storage and were read back with a matching
 * digest, so a retry may publish them; `rendered` means they are not, so it may not.
 */
enum FinalizationRunState: string
{
    /** The input snapshot and the generation were captured under the envelope lock. */
    case Started = 'started';

    /** The executed PDF was rendered, sealed, and validated, but nothing is in storage yet. */
    case Rendered = 'rendered';

    /** Every artifact's bytes are in storage and read back with a matching digest. */
    case Uploaded = 'uploaded';

    /** The artifact rows exist and the envelope is completed. Terminal and successful. */
    case Published = 'published';

    /** The attempt did not publish. Retryable; never a completion. */
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
