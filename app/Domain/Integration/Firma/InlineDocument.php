<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

/**
 * A PDF sent as base64 in a JSON body: `document` on the create routes, and on the
 * small-document upload (issue #182). One decoder, so both refuse the same things the same way.
 */
final class InlineDocument
{
    /**
     * @param  string  $member  The request member, named in the refusal.
     *
     * @throws FirmaException
     */
    public static function decode(string $base64, string $member = 'document'): string
    {
        // Data URLs turn up in practice even where the contract says base64.
        $payload = preg_replace('#^data:application/pdf;base64,#i', '', trim($base64)) ?? $base64;
        $bytes = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($bytes === false || $bytes === '') {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                '`'.$member.'` is not valid base64.',
            );
        }

        if (strlen($bytes) > FirmaProfile::MAX_INLINE_DOCUMENT_BYTES) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'The inline `'.$member.'` decodes to '.strlen($bytes).' bytes, over the '
                .FirmaProfile::MAX_INLINE_DOCUMENT_BYTES.'-byte limit for a document sent in a JSON body. '
                .'Upload a larger file in two steps: `POST /documents`, then PUT the bytes to the `upload_url` it returns.',
            );
        }

        return $bytes;
    }
}
