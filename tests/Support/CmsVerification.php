<?php

declare(strict_types=1);

namespace Tests\Support;

use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use RuntimeException;

/**
 * Verifies a sealed PDF's detached CMS with PHP's own OpenSSL binding.
 *
 * This is a *second opinion*, not a duplicate of the application's validator. The validator
 * verifies through `tecnickcom/tc-lib-pdf-sign`, the same library that produced the signature;
 * a fault common to both directions would be invisible to it, which is the limitation
 * docs/stage0/sealing.md states plainly. Re-checking the same bytes through OpenSSL means the
 * signature has to satisfy two independent implementations of CMS.
 *
 * It is still in-process, so it is not the authoritative check either: that is pyHanko in the
 * `validation` CI job (`scripts/validate-seal.sh`), which shares no code with the PHP path at
 * all.
 */
final class CmsVerification
{
    /**
     * True when the CMS in the last signature dictionary verifies over the byte-ranged bytes.
     *
     * Certificate *trust* is deliberately not checked here (`OPENSSL_CMS_NOVERIFY`): the seal
     * certificate is a fixture with no path to any real anchor, and whether a relying party
     * trusts an issuer is not a property of the bytes. What is checked is the claim the bytes
     * make — that this signer signed this content.
     */
    public static function verifies(string $pdf, ?string &$signerPem = null): bool
    {
        [$content, $der] = self::parts($pdf);

        $contentFile = tempnam(sys_get_temp_dir(), 'esign-cms-content-');
        $cmsFile = tempnam(sys_get_temp_dir(), 'esign-cms-der-');
        $signersFile = tempnam(sys_get_temp_dir(), 'esign-cms-signers-');

        try {
            file_put_contents($contentFile, $content);
            file_put_contents($cmsFile, $der);

            $verified = openssl_cms_verify(
                $contentFile,
                OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
                $signersFile,
                [],
                null,
                null,
                null,
                $cmsFile,
                OPENSSL_ENCODING_DER,
            );

            // A queued error would be reported against the next unrelated OpenSSL call.
            while (openssl_error_string() !== false) {
                // discarded
            }

            $signerPem = $verified ? (string) file_get_contents($signersFile) : null;

            return $verified;
        } finally {
            @unlink($contentFile);
            @unlink($cmsFile);
            @unlink($signersFile);
        }
    }

    /**
     * The byte-ranged content and the DER CMS of the last signature dictionary.
     *
     * @return array{string, string}
     */
    private static function parts(string $pdf): array
    {
        $matches = [];
        $found = preg_match_all(
            '/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/',
            $pdf,
            $matches,
            PREG_SET_ORDER,
        );

        if ($found === false || $found === 0) {
            throw new RuntimeException('The artifact carries no /ByteRange.');
        }

        $last = $matches[$found - 1];
        [$offsetA, $lengthA, $offsetB, $lengthB] = [
            (int) $last[1], (int) $last[2], (int) $last[3], (int) $last[4],
        ];

        $content = substr($pdf, $offsetA, $lengthA).substr($pdf, $offsetB, $lengthB);

        // The /Contents field is a fixed-size zero-padded hex string; take the CMS by its own
        // DER length rather than by trimming zeroes, which would corrupt a signature ending
        // in 0x00.
        $padded = hex2bin(trim(substr($pdf, $lengthA + 1, $offsetB - $lengthA - 2)));

        if ($padded === false) {
            throw new RuntimeException('The /Contents field is not decodable hex.');
        }

        $cursor = 0;

        return [$content, (new Asn1)->readTlv($padded, $cursor)['raw']];
    }
}
