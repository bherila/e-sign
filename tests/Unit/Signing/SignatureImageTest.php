<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Signing\Capture\SignatureImage;
use App\Domain\Signing\Capture\SignatureImageRejected;
use App\Domain\Signing\Fields\FieldValueValidator;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticImages;

/**
 * Controlled rendering of a submitted signature.
 *
 * docs/HANDOFF.md section 8: "decode and re-encode permitted images with dimensions/size
 * limits; do not accept arbitrary SVG/HTML or remote image URLs." The re-encode half is the
 * one worth a test of its own — validating a file and then storing it verbatim leaves
 * whatever else was in it on the agreement and inside the sealed PDF, and only a trip through
 * a pixel buffer removes that.
 *
 * A pure unit test: the class takes a config repository and nothing else, so there is no
 * reason to boot an application to exercise it.
 */
class SignatureImageTest extends TestCase
{
    public function test_a_png_is_decoded_and_re_encoded(): void
    {
        $submitted = SyntheticImages::pngDataUrl(300, 100);
        $stored = $this->images()->reencode($submitted);

        $this->assertStringStartsWith('data:image/png;base64,', $stored);
        $this->assertNotSame($submitted, $stored, 'A stored image must be bytes this application produced.');

        // And what came out is still the same picture, at the same size.
        $size = getimagesizefromstring($this->binaryOf($stored));
        $this->assertIsArray($size);
        $this->assertSame(300, $size[0]);
        $this->assertSame(100, $size[1]);
        $this->assertSame('image/png', $size['mime']);
    }

    public function test_a_jpeg_is_accepted_and_leaves_as_a_png(): void
    {
        $stored = $this->images()->reencode(SyntheticImages::jpegDataUrl(200, 80));

        $this->assertStringStartsWith('data:image/png;base64,', $stored);
    }

    public function test_metadata_in_the_submitted_file_does_not_survive(): void
    {
        // A PNG with a text chunk carrying something that must not reach a sealed document.
        $marker = 'esign-must-not-survive-re-encoding';
        $withComment = $this->pngWithTextChunk($marker);

        $this->assertStringContainsString($marker, $withComment, 'The fixture must actually contain it.');

        $stored = $this->images()->reencode('data:image/png;base64,'.base64_encode($withComment));

        $this->assertStringNotContainsString($marker, $this->binaryOf($stored));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusals(): array
    {
        return [
            'an SVG document' => [SyntheticImages::svgDataUrl(), 'unsupported_type'],
            'HTML wearing an image content type' => [SyntheticImages::notAnImageDataUrl(), 'not_decodable'],
            'a remote URL' => ['https://attacker.example.test/signature.png', 'not_a_data_url'],
            'a protocol-relative URL' => ['//attacker.example.test/signature.png', 'not_a_data_url'],
            'a percent-encoded data URL' => ['data:image/png,%89PNG', 'not_a_data_url'],
            'an empty string' => ['', 'not_a_data_url'],
            'a bare word' => ['signed', 'not_a_data_url'],
            'a GIF' => ['data:image/gif;base64,'.base64_encode('GIF89a'), 'unsupported_type'],
            'an unparseable base64 payload' => ['data:image/png;base64,====', 'not_decodable'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_it_refuses(string $submitted, string $reason): void
    {
        try {
            $this->images()->reencode($submitted);
            $this->fail('Expected the submission to be refused.');
        } catch (SignatureImageRejected $rejected) {
            $this->assertSame($reason, $rejected->reason);
        }
    }

    public function test_an_image_wider_than_the_ceiling_is_refused(): void
    {
        $images = $this->images(['max_signature_image_width' => 100]);

        $this->expectException(SignatureImageRejected::class);
        $images->reencode(SyntheticImages::pngDataUrl(101, 20));
    }

    public function test_an_image_taller_than_the_ceiling_is_refused(): void
    {
        $images = $this->images(['max_signature_image_height' => 50]);

        $this->expectException(SignatureImageRejected::class);
        $images->reencode(SyntheticImages::pngDataUrl(20, 51));
    }

    public function test_an_image_larger_than_the_byte_ceiling_is_refused(): void
    {
        $images = $this->images(['max_signature_image_bytes' => 1_024]);

        try {
            $images->reencode(SyntheticImages::pngDataUrl(1_500, 700));
            $this->fail('Expected the submission to be refused.');
        } catch (SignatureImageRejected $rejected) {
            $this->assertSame('too_many_bytes', $rejected->reason);
        }
    }

    /**
     * docs/security/review-2026-09.md finding G-2.
     *
     * The byte ceiling used to be measured on the *decoded* bytes, so a caller could spend
     * the whole 64 MB request body the shipped `public/.user.ini` allows and the process
     * would run a regular expression, take a capture group, strip whitespace, and base64
     * decode all of it before discovering the value was three hundred times its ceiling.
     * The encoded length is now measured first, so an oversized submission costs one
     * `strlen`.
     */
    public function test_an_oversized_payload_is_refused_before_it_is_decoded(): void
    {
        $images = $this->images(['max_signature_image_bytes' => 1_024]);

        // Well-formed for the data-URL grammar, and vastly over the ceiling. If the guard
        // were removed this would still be refused — one decode later, and after allocating
        // several copies of it.
        $submitted = 'data:image/png;base64,'.str_repeat('A', 1_024 * 8);

        try {
            $images->reencode($submitted);
            $this->fail('Expected the submission to be refused.');
        } catch (SignatureImageRejected $rejected) {
            $this->assertSame('too_many_bytes', $rejected->reason);
            $this->assertStringContainsString((string) strlen($submitted), $rejected->getMessage());
        }
    }

    public function test_a_submission_at_the_ceiling_still_passes_the_encoded_length_guard(): void
    {
        // Base64 is 4 characters per 3 bytes, so the encoded form of a submission at the
        // ceiling is about 1.34x the ceiling. The guard is at 2x; this pins that it cannot
        // be tightened into rejecting a legitimate signature.
        $stored = $this->images()->reencode(SyntheticImages::pngDataUrl(1_000, 400));

        $this->assertStringStartsWith('data:image/png;base64,', $stored);
    }

    public function test_the_output_always_fits_the_column_it_is_stored_in(): void
    {
        $stored = $this->images()->reencode(SyntheticImages::pngDataUrl(1_000, 400));

        $this->assertLessThanOrEqual(FieldValueValidator::MAX_SIGNATURE_LENGTH, mb_strlen($stored));
    }

    public function test_the_declared_type_is_checked_before_the_bytes_are_decoded(): void
    {
        // A real PNG announced as an SVG. Refused by name, so nothing this process has to
        // reason about is ever built from a type it does not accept.
        $png = SyntheticImages::pngBytes(10, 10);

        try {
            $this->images()->reencode('data:image/svg+xml;base64,'.base64_encode($png));
            $this->fail('Expected the submission to be refused.');
        } catch (SignatureImageRejected $rejected) {
            $this->assertSame('unsupported_type', $rejected->reason);
        }
    }

    public function test_the_sniffed_type_wins_over_the_declared_one(): void
    {
        // A JPEG announced as a PNG. The declaration passes the name check and the bytes then
        // disagree with it, which is either a broken client or a probe; both are refused.
        $jpeg = base64_decode(substr(SyntheticImages::jpegDataUrl(10, 10), strlen('data:image/jpeg;base64,')), true);

        try {
            $this->images()->reencode('data:image/png;base64,'.base64_encode((string) $jpeg));
            $this->fail('Expected the submission to be refused.');
        } catch (SignatureImageRejected $rejected) {
            $this->assertSame('unsupported_type', $rejected->reason);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function images(array $overrides = []): SignatureImage
    {
        return new SignatureImage(new Repository(['esign' => ['signing' => array_replace([
            'max_signature_image_bytes' => 204_800,
            'max_signature_image_width' => 2_000,
            'max_signature_image_height' => 800,
        ], $overrides)]]));
    }

    private function binaryOf(string $dataUrl): string
    {
        return (string) base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
    }

    /** A valid PNG with a tEXt chunk, built by hand so the marker is definitely in the file. */
    private function pngWithTextChunk(string $marker): string
    {
        $png = SyntheticImages::pngBytes(10, 10);
        $payload = "Comment\0".$marker;
        $chunk = pack('N', strlen($payload)).'tEXt'.$payload.pack('N', crc32('tEXt'.$payload));

        // Insert immediately after the 8-byte signature and the IHDR chunk (25 bytes).
        return substr($png, 0, 33).$chunk.substr($png, 33);
    }
}
