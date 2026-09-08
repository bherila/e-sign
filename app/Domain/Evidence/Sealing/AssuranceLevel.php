<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

/**
 * The PAdES baseline level an artifact is required to reach.
 *
 * ETSI EN 319 142-1 V1.2.1 (2024-01) defines the baseline profiles. Only the
 * two levels this application can actually produce and verify are listed: a
 * level named in an enum but not reachable end to end would be a claim, not a
 * capability. B-LT and B-LTA are deliberately absent (see docs/stage0/sealing.md).
 */
enum AssuranceLevel: string
{
    /** CAdES-based detached CMS in the PDF signature dictionary. */
    case PadesBB = 'pades-b-b';

    /** B-B plus an RFC 3161 signature timestamp from a configured TSA. */
    case PadesBT = 'pades-b-t';

    /**
     * True when the level requires a timestamp authority.
     *
     * A deployment without one cannot satisfy this level, and must fail rather
     * than return the level below.
     */
    public function requiresTimestamp(): bool
    {
        return $this === self::PadesBT;
    }

    /**
     * The tc-lib-pdf signature profile identifier for this level.
     *
     * The backing values match Com\Tecnick\Pdf\Sign\SignatureProfile, which is
     * the closed set the engine validates against.
     */
    public function engineProfile(): string
    {
        return $this->value;
    }
}
