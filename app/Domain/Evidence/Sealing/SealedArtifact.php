<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

/**
 * The result of a successful seal.
 *
 * Only the level actually reached is recorded. A sealer never returns this
 * object with a level below the one requested; it throws instead.
 *
 * The digest is computed over the sealed bytes from outside the document, so
 * it is not a self-referential "final hash inside itself".
 */
final readonly class SealedArtifact
{
    /**
     * @param  string  $pdf  Raw bytes of the sealed PDF.
     * @param  AssuranceLevel  $level  The level reached, which equals the level requested.
     * @param  string  $keyId  Versioned identifier of the seal material used.
     * @param  string  $digestAlgorithm  CMS digest algorithm.
     * @param  string  $sha256  Lowercase hex SHA-256 over $pdf.
     * @param  string|null  $timestampAuthority  TSA endpoint used, or null for B-B.
     */
    public function __construct(
        public string $pdf,
        public AssuranceLevel $level,
        public string $keyId,
        public string $digestAlgorithm,
        public string $sha256,
        public ?string $timestampAuthority = null,
    ) {}
}
