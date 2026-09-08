<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Contracts;

use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use DateTimeImmutable;

/**
 * The public facts about the configured service seal, without the private key.
 *
 * Three callers need to know *which* seal material this deployment holds without asking it to
 * sign anything: the completion report names the key id on a page bound into the document
 * before it is sealed, every artifact row records the key id and certificate digest so a
 * document stays attributable after a rotation (issue #29), and the evidence export ships the
 * certificate chain so a relying party can verify an old artifact with the key that made it.
 *
 * Deliberately a separate port from {@see PdfSealer}. Sealing is the privileged operation and
 * in Docker its material is mounted only into the worker role; reading the certificate's own
 * identity is not, and a status command should not have to be able to sign to report an
 * expiry date. Nothing here returns, logs, or serializes a private key.
 */
interface SealIdentity
{
    /**
     * Versioned identifier of the configured material, recorded on every artifact it seals.
     *
     * @throws SealingException When no usable material is configured.
     */
    public function keyId(): string;

    /**
     * Lowercase hex SHA-256 over the seal certificate's DER, as a validator reports it.
     *
     * @throws SealingException
     */
    public function certificateFingerprint(): string;

    /**
     * Human-readable certificate subject, for the status command and for evidence.
     *
     * @throws SealingException
     */
    public function subject(): string;

    /**
     * Certificate expiry, for expiry monitoring and rotation planning.
     *
     * @throws SealingException
     */
    public function notAfter(): DateTimeImmutable;

    /**
     * The seal certificate in PEM form, for the evidence export.
     *
     * @throws SealingException
     */
    public function certificatePem(): string;

    /**
     * The PEM bundle above the seal certificate, leaf first, or '' when none is configured.
     *
     * @throws SealingException
     */
    public function chainPem(): string;

    /** The CMS digest algorithm the material is configured for.
     *
     * @throws SealingException
     */
    public function digestAlgorithm(): string;

    /**
     * True when an RFC 3161 timestamp authority is configured.
     *
     * False means the deployment cannot produce PAdES B-T at all. It never means B-T should
     * quietly become B-B.
     */
    public function hasTimestampAuthority(): bool;
}
