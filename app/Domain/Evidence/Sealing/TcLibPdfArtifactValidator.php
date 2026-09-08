<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

use App\Domain\Evidence\Contracts\ArtifactValidator;
use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Com\Tecnick\Pdf\Sign\Cms\SignedDataVerifier;
use Com\Tecnick\Pdf\Sign\DigestAlgorithm;
use Com\Tecnick\Pdf\Sign\Exception as SignException;
use OpenSSLCertificate;
use Throwable;

/**
 * Reads a sealed PDF back and reports the signature it actually carries.
 *
 * Two layers are involved. Locating the signature dictionary, the
 * `/ByteRange`, and the `/Contents` hole is PDF syntax, done here. Everything
 * cryptographic — decoding the CMS, checking the SignerInfo signature against
 * the certificate it names, reading the digest AlgorithmIdentifier, finding
 * the signature timestamp attribute — is delegated to tc-lib-pdf-sign.
 *
 * This is a self-check that shares the signing library, so it cannot detect a
 * fault common to signing and verification. It gates publication; it does not
 * substitute for the independent validators in scripts/validate-seal.sh.
 */
final class TcLibPdfArtifactValidator implements ArtifactValidator
{
    public function validate(string $pdf): ValidationReport
    {
        if ($pdf === '' || ! str_starts_with($pdf, '%PDF-')) {
            return ValidationReport::unreadable(['The bytes are not a PDF document.']);
        }

        $byteRange = $this->byteRange($pdf);
        if ($byteRange === null) {
            return ValidationReport::unreadable(['No signature dictionary with a /ByteRange was found.']);
        }

        [$start, $signatureOffset, $afterSignature, $afterSignatureLength] = $byteRange;
        $length = strlen($pdf);
        $failures = [];

        // A /Contents hole outside the file, or a range that does not begin at
        // byte zero, means the signature cannot be re-created from these bytes.
        if ($start !== 0) {
            $failures[] = 'The /ByteRange does not start at the beginning of the file.';
        }

        if ($signatureOffset < 2 || $afterSignature < $signatureOffset || $afterSignature > $length) {
            return ValidationReport::unreadable(['The /ByteRange does not describe a region of this file.']);
        }

        $coveredEnd = $afterSignature + $afterSignatureLength;
        $coversWholeFile = $start === 0 && $coveredEnd === $length;
        if (! $coversWholeFile) {
            $failures[] = $coveredEnd > $length
                ? 'The /ByteRange claims '.$coveredEnd.' bytes but the file holds '.$length.
                  '; the file is truncated or the range is forged.'
                : 'The /ByteRange leaves '.($length - $coveredEnd).
                  ' bytes at the end of the file uncovered by the signature.';
        }

        // The hole is the hex string, angle brackets included.
        $hex = substr($pdf, $signatureOffset + 1, $afterSignature - $signatureOffset - 2);
        $der = $this->decodeContents($hex);
        if ($der === null) {
            return ValidationReport::unreadable([...$failures, 'The signature /Contents is not decodable hex.']);
        }

        $signedContent = substr($pdf, $start, $signatureOffset - $start)
            .substr($pdf, $afterSignature, min($afterSignatureLength, max(0, $length - $afterSignature)));

        $subFilter = $this->subFilter($pdf);
        if ($subFilter !== 'ETSI.CAdES.detached') {
            $failures[] = $subFilter === ''
                ? 'The signature dictionary declares no /SubFilter.'
                : 'The signature /SubFilter is '.$subFilter.', not the PAdES ETSI.CAdES.detached.';
        }

        $signerSubject = '';
        $signerFingerprint = '';
        $sound = false;
        try {
            $signerCertificate = (new SignedDataVerifier)->verify($der, $signedContent);
            $signerSubject = $this->subjectOf($signerCertificate);
            $signerFingerprint = hash('sha256', $signerCertificate);
            $sound = true;
        } catch (SignException $e) {
            $failures[] = 'The CMS signature does not verify over the byte-ranged content: '.$e->getMessage();
        } catch (Throwable $e) {
            $failures[] = 'The CMS signature could not be checked: '.$e->getMessage();
        }

        return new ValidationReport(
            signed: true,
            subFilter: $subFilter,
            digestAlgorithm: $this->digestAlgorithm($der),
            coversWholeFile: $coversWholeFile,
            cryptographicallySound: $sound,
            hasSignatureTimestamp: $this->hasSignatureTimestamp($der),
            revisions: substr_count($pdf, '%%EOF'),
            signerSubject: $signerSubject,
            signerFingerprint: $signerFingerprint,
            failures: $failures,
        );
    }

    /**
     * The four /ByteRange integers of the last signature dictionary.
     *
     * @return array{int, int, int, int}|null
     */
    private function byteRange(string $pdf): ?array
    {
        $matches = [];
        $found = preg_match_all(
            '/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/',
            $pdf,
            $matches,
            PREG_SET_ORDER
        );

        if ($found === false || $found === 0) {
            return null;
        }

        // The approval signature this application applies is the last one
        // written, and its range is the one that must cover the whole file.
        //
        // Only that one is reported. This pipeline seals once and refuses an
        // already-signed input, so a multi-signature artifact is outside what
        // it produces; a file carrying several is a case for the external
        // validators, which analyse every field.
        $last = $matches[$found - 1];

        return [(int) $last[1], (int) $last[2], (int) $last[3], (int) $last[4]];
    }

    private function subFilter(string $pdf): string
    {
        $matches = [];
        $found = preg_match_all('/\/SubFilter\s*\/([A-Za-z0-9._-]+)/', $pdf, $matches, PREG_SET_ORDER);
        if ($found === false || $found === 0) {
            return '';
        }

        return (string) $matches[$found - 1][1];
    }

    /**
     * Decode the /Contents hex string, dropping the reserved zero padding.
     *
     * The padding is removed by reading the length out of the CMS element's own
     * DER header, not by trimming trailing zero characters: a signature whose
     * last byte is 0x00 is perfectly ordinary, and trimming would silently eat
     * it, producing an intermittent verification failure on roughly one seal in
     * a few hundred.
     */
    private function decodeContents(string $hex): ?string
    {
        $trimmed = trim($hex);

        if ($trimmed === '' || strlen($trimmed) % 2 === 1 || preg_match('/^[0-9A-Fa-f]+$/', $trimmed) !== 1) {
            return null;
        }

        $padded = hex2bin($trimmed);
        if ($padded === false) {
            return null;
        }

        try {
            $offset = 0;

            return (new Asn1)->readTlv($padded, $offset)['raw'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The digest algorithm the SignedData declares, or '' when unreadable.
     */
    private function digestAlgorithm(string $der): string
    {
        try {
            $asn1 = new Asn1;
            $signedData = (new Certificate($asn1))->signedDataContent($der);

            $offset = 0;
            $asn1->readTlv($signedData, $offset);                     // version
            $algorithms = $asn1->readTlv($signedData, $offset);       // digestAlgorithms SET

            // One signer, so one digest: more than one entry is not something
            // this application produces, and is reported as unreadable rather
            // than reduced to whichever came first.
            $element = $asn1->readSingleElement($algorithms['value'], 0x30, 'digestAlgorithms');
            $oid = $asn1->decodeAlgorithmIdentifier($element['raw'], 'digestAlgorithm');

            return DigestAlgorithm::tryFromOid($oid)?->value ?? $oid;
        } catch (Throwable) {
            return '';
        }
    }

    private function hasSignatureTimestamp(string $der): bool
    {
        try {
            return (new Certificate)->signatureTimestampTokens($der) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function subjectOf(string $certificateDer): string
    {
        // Silenced deliberately: an unreadable certificate is reported as an
        // empty subject, and the OpenSSL warning adds nothing a caller can use.
        $certificate = @openssl_x509_read(Certificate::derToPem($certificateDer));
        if (! $certificate instanceof OpenSSLCertificate) {
            Certificate::clearOpenSslErrors();

            return '';
        }

        $parsed = @openssl_x509_parse($certificate);

        return is_array($parsed) && isset($parsed['name']) && is_string($parsed['name'])
            ? $parsed['name']
            : '';
    }
}
