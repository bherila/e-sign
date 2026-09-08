<?php

declare(strict_types=1);

namespace App\Domain\Signing\Capture;

use App\Domain\Signing\Fields\FieldValueValidator;
use GdImage;
use Illuminate\Contracts\Config\Repository;

/**
 * Turns whatever a browser submitted for a signature into bytes this application produced.
 *
 * docs/HANDOFF.md section 8: "Render signature submissions through controlled assets: decode
 * and re-encode permitted images with dimensions/size limits; do not accept arbitrary
 * SVG/HTML or remote image URLs."
 *
 * The important word is *re-encode*. Validating a submitted image and then storing the
 * submitted bytes leaves whatever else was in the file — an XMP block, an ICC profile, a
 * comment segment, a polyglot payload that is a valid PNG and a valid something-else — on
 * the agreement and in the sealed PDF. Decoding to a pixel buffer and encoding a fresh PNG
 * from it means the stored artifact contains a raster and nothing else, because nothing else
 * survives the trip through a pixel buffer.
 *
 * ## What is refused, and why refused rather than sanitized
 *
 * | Input | Outcome |
 * |---|---|
 * | `https://…/signature.png` | refused. A remote URL is a request the server or the reader makes later, to a host somebody else controls, at a time nobody chose. |
 * | `data:image/svg+xml,…` | refused. SVG is a document format with scripting and external references; there is no subset of it that is "just a picture", and a sanitizer for it is a permanent liability. |
 * | HTML, or anything GD cannot decode | refused. |
 * | a PNG whose declared type is `image/jpeg` | refused. The declared type and the sniffed type must agree; a mismatch is either a broken client or a probe. |
 * | an image past the byte or pixel ceilings | refused, with the numbers, so the page can say what to do. |
 *
 * There is no "clean it up and carry on" branch anywhere. An allowlist of two raster formats
 * that both round-trip through a pixel buffer is a boundary that can be reasoned about; a
 * sanitizer is a promise to keep up with everyone who has ever embedded something in a file.
 *
 * ## What it is not
 *
 * It is not evidence of intent. A decoded image proves an image was drawn, and
 * docs/HANDOFF.md section 8 is explicit that a signature must never be recorded merely
 * because a canvas is non-empty. Intent is the consent checkbox, the stated agreement, and
 * the attestation the state machine writes; this class only makes sure that what gets drawn
 * on the page is a picture.
 */
final class SignatureImage
{
    /** The two raster formats accepted, by their `image/*` names. */
    public const ALLOWED_TYPES = ['image/png', 'image/jpeg'];

    /**
     * How much larger than the input ceiling a re-encoded PNG may be.
     *
     * Re-encoding can legitimately grow a file — a photographed signature arrives as a small
     * lossy JPEG and leaves as a lossless PNG — so measuring the output against the input
     * ceiling would reject valid submissions. It still needs *a* ceiling, because the result
     * is stored in a text column and travels inside a PDF.
     */
    private const OUTPUT_GROWTH_FACTOR = 4;

    public function __construct(private readonly Repository $config) {}

    /**
     * Decode a submitted data URL and return a freshly encoded PNG data URL.
     *
     * The return value is what gets stored as the field's value, so it is deliberately the
     * same *shape* as the input: a page that renders the stored value needs no branch for
     * "was this one re-encoded".
     *
     * @throws SignatureImageRejected
     */
    public function reencode(string $submitted): string
    {
        [$declared, $binary] = $this->decodeDataUrl($submitted);

        $maxBytes = $this->maxBytes();

        if (strlen($binary) > $maxBytes) {
            throw SignatureImageRejected::tooManyBytes(strlen($binary), $maxBytes);
        }

        // Sniffed from the bytes, never from the declared type. `getimagesizefromstring`
        // returns false for anything it does not recognise, which is most of what an
        // attacker would send and all of what a broken client would.
        $size = @getimagesizefromstring($binary);

        if ($size === false || ! isset($size[0], $size[1], $size['mime'])) {
            throw SignatureImageRejected::notDecodable();
        }

        $sniffed = (string) $size['mime'];

        if (! in_array($sniffed, self::ALLOWED_TYPES, true) || $sniffed !== $declared) {
            // Both conditions, because they catch different things. The first refuses a
            // format this application does not render; the second refuses a permitted format
            // announced as a different permitted format, which is either a broken client or
            // somebody testing whether the two checks are independent.
            throw SignatureImageRejected::unsupportedType($sniffed);
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];
        $maxWidth = $this->maxWidth();
        $maxHeight = $this->maxHeight();

        if ($width < 1 || $height < 1 || $width > $maxWidth || $height > $maxHeight) {
            throw SignatureImageRejected::tooLarge($width, $height, $maxWidth, $maxHeight);
        }

        $image = @imagecreatefromstring($binary);

        if (! $image instanceof GdImage) {
            throw SignatureImageRejected::notDecodable();
        }

        // No imagedestroy(): it has had no effect since PHP 8.0 and is deprecated in 8.5.
        return $this->encodePng($image, $maxBytes);
    }

    /**
     * Split a `data:` URL into its declared type and its bytes.
     *
     * Anything that is not a base64 `data:` URL is refused outright, including a plain URL
     * and including a `data:` URL with percent-encoded rather than base64 content — the
     * client this serves emits exactly one form, and accepting more shapes only widens what
     * has to be reasoned about.
     *
     * @return array{string, string} The declared type, and the decoded bytes.
     *
     * @throws SignatureImageRejected
     */
    private function decodeDataUrl(string $submitted): array
    {
        $submitted = trim($submitted);

        if (preg_match('#^data:([a-z0-9.+/-]+);base64,([A-Za-z0-9+/=\s]+)$#i', $submitted, $matches) !== 1) {
            throw SignatureImageRejected::notADataUrl();
        }

        $declared = strtolower($matches[1]);

        // Checked before decoding as well as after, so `data:image/svg+xml;base64,…` is
        // refused by name and never becomes a string this process has to reason about.
        if (! in_array($declared, self::ALLOWED_TYPES, true)) {
            throw SignatureImageRejected::unsupportedType($declared);
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $matches[2]) ?? '', true);

        if ($binary === false || $binary === '') {
            throw SignatureImageRejected::notDecodable();
        }

        return [$declared, $binary];
    }

    /**
     * @throws SignatureImageRejected
     */
    private function encodePng(GdImage $image, int $maxBytes): string
    {
        // A drawn signature is a stroke on transparency, and a PNG written without these two
        // calls composites it onto black. Set before capturing output, not after.
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $written = imagepng($image, null, 9);
        $encoded = (string) ob_get_clean();

        if ($written === false || $encoded === '') {
            throw SignatureImageRejected::notReencodable();
        }

        $outputCeiling = $maxBytes * self::OUTPUT_GROWTH_FACTOR;

        if (strlen($encoded) > $outputCeiling) {
            throw SignatureImageRejected::tooManyBytes(strlen($encoded), $outputCeiling);
        }

        $dataUrl = 'data:image/png;base64,'.base64_encode($encoded);

        // The last gate is the column the value lands in. FieldValueValidator would refuse a
        // longer string anyway; catching it here means the signer is told their signature was
        // too large rather than being shown a generic field-validation failure.
        if (mb_strlen($dataUrl) > FieldValueValidator::MAX_SIGNATURE_LENGTH) {
            throw SignatureImageRejected::tooManyBytes(
                mb_strlen($dataUrl),
                FieldValueValidator::MAX_SIGNATURE_LENGTH,
            );
        }

        return $dataUrl;
    }

    public function maxBytes(): int
    {
        return max(1_024, (int) $this->config->get('esign.signing.max_signature_image_bytes', 204_800));
    }

    public function maxWidth(): int
    {
        return max(1, (int) $this->config->get('esign.signing.max_signature_image_width', 2_000));
    }

    public function maxHeight(): int
    {
        return max(1, (int) $this->config->get('esign.signing.max_signature_image_height', 800));
    }
}
