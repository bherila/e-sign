<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\HttpTimestampAuthority;
use App\Domain\Evidence\Sealing\SealedArtifact;
use App\Domain\Evidence\Sealing\SealMaterial;
use App\Domain\Evidence\Sealing\SealRequest;
use App\Domain\Evidence\Sealing\TcLibPdfArtifactValidator;
use App\Domain\Evidence\Sealing\TcLibPdfSealer;
use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use Com\Tecnick\Pdf\Tcpdf;
use RuntimeException;

/**
 * Builders for the synthetic inputs, key material, and tampered artifacts the
 * sealing tests and the independent-validation fixtures both need.
 *
 * Everything here is generated: no real agreement, name, or address, and no key
 * that is used anywhere.
 */
final class SealingFixtures
{
    /** Passphrase of seal-encrypted.test.pkey, as written by generate.sh. */
    public const TEST_PASSPHRASE = 'esign-fixture-passphrase-not-a-secret';

    public const KEY_ID = 'fixture-seal-2026-a';

    public static function cryptoPath(string $file = ''): string
    {
        return __DIR__.'/../Fixtures/crypto'.($file === '' ? '' : '/'.$file);
    }

    public static function validationPath(string $file = ''): string
    {
        return __DIR__.'/../Fixtures/validation'.($file === '' ? '' : '/'.$file);
    }

    public static function pem(string $file): string
    {
        $contents = file_get_contents(self::cryptoPath($file));
        if ($contents === false) {
            throw new RuntimeException('Missing fixture '.$file.'; run tests/Fixtures/crypto/generate.sh.');
        }

        return $contents;
    }

    /**
     * A synthetic single-page PDF.
     *
     * Drawn with vector graphics rather than text: tc-lib-pdf resolves even the
     * standard-14 core fonts through a generated `<font>.json` metrics file, and
     * the Composer dist package ships none (they are a `make fonts` build
     * artifact). Sealing does not depend on page content, so the fixture stays
     * text-free rather than adding a font bootstrap to the test run. Recorded as
     * an open gap in docs/stage0/sealing.md.
     */
    public static function syntheticPdf(string $marker = 'A'): string
    {
        $pdf = new Tcpdf;
        $pdf->setCreator('BWH eSign Stage 0 synthetic fixture');
        $pdf->setTitle('SYNTHETIC TEST AGREEMENT '.$marker.' - NOT A REAL DOCUMENT');
        $pdf->setSubject('Synthetic fixture for PAdES sealing feasibility. Contains no agreement text.');
        $pdf->addPage();

        // A frame plus a signature-block rule, so a human opening the artifact
        // sees a page rather than a blank one. The marker shifts the geometry so
        // two fixtures differ in their signed bytes.
        $offset = $marker === 'A' ? 0.0 : 7.0;
        $pdf->page->addContent($pdf->graph->getRect(15, 15, 180, 267));
        $pdf->page->addContent($pdf->graph->getLine(25, 240 + $offset, 110, 240 + $offset));
        $pdf->page->addContent($pdf->graph->getRect(25, 250 + $offset, 85, 20));

        return $pdf->getOutPDFString();
    }

    public static function material(
        string $certificate = 'seal.test.crt',
        string $privateKey = 'seal.test.pkey',
        string $passphrase = '',
        string $chain = 'root.test.crt',
    ): SealMaterial {
        return SealMaterial::fromPem(
            keyId: self::KEY_ID,
            certificatePem: self::pem($certificate),
            privateKeyPem: self::pem($privateKey),
            chainPem: $chain === '' ? '' : self::pem($chain),
            digestAlgorithm: 'sha256',
            passphrase: $passphrase,
        );
    }

    public static function sealer(
        ?SealMaterial $material = null,
        ?TimestampAuthority $timestampAuthority = null,
        bool $allowSha1TimestampToken = true,
    ): TcLibPdfSealer {
        $resolved = $material ?? self::material();

        return new TcLibPdfSealer(
            material: static fn (): SealMaterial => $resolved,
            timestampAuthority: $timestampAuthority ?? new HttpTimestampAuthority(''),
            validator: new TcLibPdfArtifactValidator,
            allowSha1TimestampToken: $allowSha1TimestampToken,
        );
    }

    public static function request(string $pdf, AssuranceLevel $level = AssuranceLevel::PadesBB): SealRequest
    {
        return new SealRequest(
            pdf: $pdf,
            level: $level,
            reason: 'Stage 0 sealing feasibility check',
            location: 'Synthetic fixture',
        );
    }

    public static function seal(
        AssuranceLevel $level = AssuranceLevel::PadesBB,
        ?SealMaterial $material = null,
        ?TimestampAuthority $timestampAuthority = null,
        string $marker = 'A',
    ): SealedArtifact {
        return self::sealer($material, $timestampAuthority)
            ->seal(self::request(self::syntheticPdf($marker), $level));
    }

    /**
     * The four /ByteRange integers of the last signature dictionary.
     *
     * @return array{int, int, int, int}
     */
    public static function byteRange(string $pdf): array
    {
        $matches = [];
        $found = preg_match_all(
            '/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/',
            $pdf,
            $matches,
            PREG_SET_ORDER
        );

        if ($found === false || $found === 0) {
            throw new RuntimeException('The artifact carries no /ByteRange.');
        }

        $last = $matches[$found - 1];

        return [(int) $last[1], (int) $last[2], (int) $last[3], (int) $last[4]];
    }

    /**
     * Change page geometry inside the signed byte range, keeping the length.
     *
     * The `/MediaBox` of the imported page sits in the first covered chunk as
     * plain ASCII, so this is a content change of exactly the kind a signature
     * has to catch: the file still parses, the page is a different size, and no
     * offset moved.
     */
    public static function modifyCoveredContent(string $pdf): string
    {
        $matches = [];
        if (preg_match('/\/MediaBox \[0\.000000 0\.000000 (\d{3})\.(\d{6}) /', $pdf, $matches) !== 1) {
            throw new RuntimeException('The artifact has no plain /MediaBox to modify.');
        }

        $original = $matches[0];
        // Same digit count, so every byte offset in the file is preserved.
        $replacement = str_replace($matches[1].'.'.$matches[2], '111.111111', $original);

        if (strlen($replacement) !== strlen($original)) {
            throw new RuntimeException('The /MediaBox replacement changed the file length.');
        }

        [, $signatureOffset] = self::byteRange($pdf);
        $position = strpos($pdf, $original);
        if ($position === false || $position >= $signatureOffset) {
            throw new RuntimeException('The /MediaBox is not inside the first signed chunk.');
        }

        return substr_replace($pdf, $replacement, $position, strlen($original));
    }

    /**
     * Drop the tail of the file.
     *
     * The result is not a readable PDF at all — the trailer and `%%EOF` go with
     * the tail — so a validator refuses it before it reaches the signature.
     * That is a real refusal and worth pinning, but it is NOT evidence about
     * the byte-range rule; use overclaimByteRange() for that.
     */
    public static function truncate(string $pdf, int $bytes = 512): string
    {
        return substr($pdf, 0, max(1, strlen($pdf) - $bytes));
    }

    /**
     * Inflate the /ByteRange's final length so it claims past the end of file.
     *
     * The complement of truncate(): the document still parses, so a validator
     * opens it, reads the signature, and refuses it because the range does not
     * describe the file. Same file length — only digits inside the array
     * change, and the engine reserves padding after it — so no offset moves.
     */
    public static function overclaimByteRange(string $pdf, int $extraBytes = 5000): string
    {
        $matches = [];
        if (preg_match('/\/ByteRange\[0 (\d+) (\d+) (\d+)\]/', $pdf, $matches) !== 1) {
            throw new RuntimeException('The artifact has no /ByteRange array to inflate.');
        }

        $inflated = str_pad((string) ((int) $matches[3] + $extraBytes), strlen($matches[3]), '0', STR_PAD_LEFT);
        if (strlen($inflated) !== strlen($matches[3])) {
            throw new RuntimeException('The inflated /ByteRange length changed the file length.');
        }

        $replacement = '/ByteRange[0 '.$matches[1].' '.$matches[2].' '.$inflated.']';

        return substr_replace($pdf, $replacement, (int) strpos($pdf, $matches[0]), strlen($matches[0]));
    }

    /**
     * Append an incremental-update revision the signature never covered.
     *
     * The revision redefines the page object with different geometry, which is
     * a substantive change to signed content rather than an added orphan
     * object. That distinction matters: a validator's document-modification
     * analysis can class an orphan as harmless "signature maintenance" and
     * still judge the signature valid, so a negative fixture has to change
     * something the analysis cares about. See docs/stage0/sealing.md.
     */
    public static function appendIncrementalUpdate(string $pdf): string
    {
        $previousStartxref = self::previousStartxref($pdf);
        $trailer = self::previousTrailerEntries($pdf);
        [$pageObjectNumber, $pageObject] = self::pageObject($pdf);

        $modified = preg_replace(
            '/\/MediaBox \[[^\]]*\]/',
            '/MediaBox [0.000000 0.000000 300.000000 400.000000]',
            $pageObject,
            1
        );

        if ($modified === null || $modified === $pageObject) {
            throw new RuntimeException('The page object has no /MediaBox to rewrite.');
        }

        $body = "\n".$modified;
        $objectOffset = strlen($pdf) + 1;
        $xrefOffset = strlen($pdf) + strlen($body);

        $update = $body
            ."xref\n0 1\n0000000000 65535 f \n"
            .$pageObjectNumber." 1\n"
            .sprintf("%010d %05d n \n", $objectOffset, 0)
            .'trailer'."\n"
            .'<< '.$trailer.' /Prev '.$previousStartxref.' >>'."\n"
            .'startxref'."\n".$xrefOffset."\n%%EOF\n";

        return $pdf.$update;
    }

    private static function previousStartxref(string $pdf): int
    {
        $matches = [];
        if (preg_match_all('/startxref\s+(\d+)/', $pdf, $matches) !== 1 && $matches[1] === []) {
            throw new RuntimeException('The artifact carries no startxref.');
        }

        return (int) end($matches[1]);
    }

    /**
     * The `/Size`, `/Root`, `/Info`, and `/ID` entries of the last trailer.
     */
    private static function previousTrailerEntries(string $pdf): string
    {
        $position = strrpos($pdf, 'trailer');
        if ($position === false) {
            throw new RuntimeException('The artifact carries no trailer.');
        }

        $matches = [];
        if (preg_match('/trailer\s*<<(.*?)>>\s*startxref/s', substr($pdf, $position), $matches) !== 1) {
            throw new RuntimeException('The artifact trailer could not be read.');
        }

        return trim($matches[1]);
    }

    /**
     * @return array{int, string} Object number and the complete `N 0 obj … endobj`.
     */
    private static function pageObject(string $pdf): array
    {
        $matches = [];
        if (preg_match('/(\d+) 0 obj\s*<<\s*\/Type \/Page\b.*?endobj/s', $pdf, $matches) !== 1) {
            throw new RuntimeException('The artifact carries no readable page object.');
        }

        return [(int) $matches[1], $matches[0]];
    }

    /**
     * Splice the CMS of a differently sealed document into this one.
     *
     * The result carries a genuine, internally consistent CMS produced by the
     * same key over other bytes: the shape a forged signature takes when the
     * `/Contents` of one artifact is lifted into another. Lengths are preserved
     * because the reserved `/Contents` field is fixed-size and zero-padded.
     */
    public static function forgeContents(string $pdf, string $donorPdf): string
    {
        [, $offset, $after] = self::byteRange($pdf);
        $holeLength = $after - $offset;

        [, $donorOffset, $donorAfter] = self::byteRange($donorPdf);
        $donorPadded = hex2bin(trim(substr($donorPdf, $donorOffset + 1, $donorAfter - $donorOffset - 2)));
        if ($donorPadded === false) {
            throw new RuntimeException('The donor /Contents is not decodable hex.');
        }

        // The exact CMS element, taken by its own DER length rather than by
        // trimming trailing zeroes, which would corrupt a signature ending 0x00.
        $cursor = 0;
        $donorHex = bin2hex((new Asn1)->readTlv($donorPadded, $cursor)['raw']);

        if (strlen($donorHex) > $holeLength - 2) {
            throw new RuntimeException('The donor CMS does not fit the reserved /Contents field.');
        }

        $forged = '<'.str_pad($donorHex, $holeLength - 2, '0').'>';

        return substr_replace($pdf, $forged, $offset, $holeLength);
    }
}
