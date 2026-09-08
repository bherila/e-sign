<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Contracts;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Evidence\Sealing\SealedArtifact;
use App\Domain\Evidence\Sealing\SealRequest;

/**
 * Applies the organizational service seal to an executed PDF.
 *
 * The seal is the service's own certificate over the finished document. It is
 * not a per-signer certificate and it does not assert an eIDAS advanced or
 * qualified signature; humans provide the electronic signatures and assent,
 * and this port seals the result.
 *
 * An implementation fails closed. It either returns an artifact at exactly the
 * requested assurance level or throws: no downgrade, no unsigned passthrough,
 * and no partially sealed bytes.
 *
 * Cryptography belongs to established libraries. An implementation must not
 * assemble CMS or ASN.1 structures, nor compute PDF signature byte ranges,
 * itself.
 */
interface PdfSealer
{
    /**
     * Seal the requested PDF.
     *
     * @throws SealingException When the material, the timestamp authority, the
     *                          input, or the engine prevents producing an
     *                          artifact at the requested level.
     */
    public function seal(SealRequest $request): SealedArtifact;

    /**
     * Check that this deployment could seal at the given level right now.
     *
     * Called before signers are invited, so an absent, unusable, expired, or
     * mismatched configuration surfaces while it can still be fixed rather
     * than at completion. Throws the same typed exceptions as seal().
     *
     * @throws SealingException
     */
    public function preflight(AssuranceLevel $level): void;
}
