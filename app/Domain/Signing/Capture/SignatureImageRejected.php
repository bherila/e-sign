<?php

declare(strict_types=1);

namespace App\Domain\Signing\Capture;

use App\Domain\Signing\Exceptions\SigningException;

/**
 * A submitted signature or initials image was not stored.
 *
 * `reason` is stable and machine-readable; `getMessage()` is what a signer is shown and says
 * what to do instead. Both are deliberately specific here, unlike the guest-access
 * refusals: there is no oracle to protect — the submitter drew the image — and "your
 * signature is too large" is actionable where "rejected" is not.
 */
final class SignatureImageRejected extends SigningException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notADataUrl(): self
    {
        return new self(
            'A signature must be an image captured on this page, not a link to one elsewhere.',
            'not_a_data_url',
        );
    }

    public static function unsupportedType(string $declared): self
    {
        return new self(
            'A signature image must be a PNG or a JPEG; "'.$declared.'" is not accepted.',
            'unsupported_type',
        );
    }

    public static function notDecodable(): self
    {
        return new self(
            'That signature image could not be read as an image.',
            'not_decodable',
        );
    }

    public static function tooManyBytes(int $bytes, int $limit): self
    {
        return new self(
            'That signature image is '.$bytes.' bytes; the limit is '.$limit.'.',
            'too_many_bytes',
        );
    }

    public static function tooLarge(int $width, int $height, int $maxWidth, int $maxHeight): self
    {
        return new self(
            'That signature image is '.$width.'x'.$height.' pixels; the limit is '
            .$maxWidth.'x'.$maxHeight.'.',
            'too_large',
        );
    }

    public static function notReencodable(): self
    {
        return new self(
            'That signature image could not be re-encoded for storage.',
            'not_reencodable',
        );
    }

    public function code(): string
    {
        return 'signature_image_rejected';
    }
}
