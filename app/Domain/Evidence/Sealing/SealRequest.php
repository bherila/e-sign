<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

/**
 * One request to seal one PDF at one assurance level.
 *
 * The input bytes are carried, not a path: the caller has already read the
 * finalization input under an envelope lock, and the sealer must not reach
 * back into storage while it works.
 */
final readonly class SealRequest
{
    /**
     * @param  string  $pdf  Raw bytes of the PDF to seal.
     * @param  AssuranceLevel  $level  Required PAdES baseline level.
     * @param  string  $reason  Signature dictionary /Reason.
     * @param  string  $location  Signature dictionary /Location.
     * @param  string  $contactInfo  Signature dictionary /ContactInfo.
     */
    public function __construct(
        public string $pdf,
        public AssuranceLevel $level,
        public string $reason = '',
        public string $location = '',
        public string $contactInfo = '',
    ) {}
}
