<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

/**
 * What a sealed PDF turned out to be when it was read back.
 *
 * Every field describes the artifact, not the request that produced it. A
 * generic "signature valid" is deliberately not offered: `isValid()` is the
 * conjunction of the individual checks, and `failures` names each one that did
 * not hold so a caller can log why publication was refused.
 */
final readonly class ValidationReport
{
    /**
     * @param  bool  $signed  A signature dictionary with a /ByteRange and /Contents was found.
     * @param  string  $subFilter  The /SubFilter value, e.g. ETSI.CAdES.detached.
     * @param  string  $digestAlgorithm  Digest named by the CMS SignerInfo.
     * @param  bool  $coversWholeFile  The /ByteRange spans the file except the /Contents hole.
     * @param  bool  $cryptographicallySound  The CMS verified over the byte-ranged bytes.
     * @param  bool  $hasSignatureTimestamp  An id-aa-signatureTimeStampToken attribute is present.
     * @param  int  $revisions  Number of `%%EOF` revisions in the file.
     * @param  string  $signerSubject  Subject of the certificate the CMS verified against.
     * @param  string  $signerFingerprint  Lowercase hex SHA-256 over that certificate's DER.
     * @param  list<string>  $failures  One message per check that did not hold.
     */
    public function __construct(
        public bool $signed,
        public string $subFilter,
        public string $digestAlgorithm,
        public bool $coversWholeFile,
        public bool $cryptographicallySound,
        public bool $hasSignatureTimestamp,
        public int $revisions,
        public string $signerSubject,
        public string $signerFingerprint,
        public array $failures,
    ) {}

    /**
     * Build a report for a file that could not be read as a signed PDF at all.
     *
     * @param  list<string>  $failures
     */
    public static function unreadable(array $failures): self
    {
        return new self(
            signed: false,
            subFilter: '',
            digestAlgorithm: '',
            coversWholeFile: false,
            cryptographicallySound: false,
            hasSignatureTimestamp: false,
            revisions: 0,
            signerSubject: '',
            signerFingerprint: '',
            failures: $failures,
        );
    }

    public function isValid(): bool
    {
        return $this->failures === []
            && $this->signed
            && $this->coversWholeFile
            && $this->cryptographicallySound;
    }

    /**
     * The highest PAdES baseline level the bytes themselves support.
     *
     * B-B needs the CAdES sub-filter and a sound detached CMS covering the
     * whole file; B-T needs a signature timestamp on top. Nothing above B-T is
     * reported, because this validator does not inspect a DSS. Null means the
     * artifact reaches no baseline level.
     */
    public function reachedLevel(): ?AssuranceLevel
    {
        if (! $this->isValid() || $this->subFilter !== 'ETSI.CAdES.detached') {
            return null;
        }

        return $this->hasSignatureTimestamp ? AssuranceLevel::PadesBT : AssuranceLevel::PadesBB;
    }
}
