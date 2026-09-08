<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityNotConfiguredException;
use Com\Tecnick\Pdf\Tcpdf;

/**
 * A tc-lib-pdf document whose TSA transport is this application's.
 *
 * tc-lib-pdf splits the RFC 3161 exchange deliberately: the library owns the
 * request/response codec and the token verification, and the host owns the
 * HTTP transport through the protected `postTimestampRequest()` seam. This
 * subclass takes that seam so the destination policy in
 * {@see HttpTimestampAuthority} applies to the timestamp request, instead of
 * the library's own cURL call which validates only the URL's shape.
 *
 * Nothing cryptographic is overridden or reimplemented here.
 */
final class SealingDocument extends Tcpdf
{
    private ?TimestampAuthority $timestampAuthority = null;

    public function useTimestampAuthority(TimestampAuthority $authority): void
    {
        $this->timestampAuthority = $authority;
    }

    /**
     * POST the DER TimeStampReq through the injected authority.
     *
     * @param  string  $request  DER-encoded RFC 3161 TimeStampReq.
     * @return string DER-encoded RFC 3161 TimeStampResp.
     */
    protected function postTimestampRequest(string $request): string
    {
        if (! $this->timestampAuthority instanceof TimestampAuthority) {
            // Unreachable through TcLibPdfSealer, which injects the authority
            // before enabling a timestamp. Failing here rather than deferring
            // to the library's own transport keeps the destination policy
            // impossible to skip by forgetting a call.
            throw new TimestampAuthorityNotConfiguredException(
                'A timestamp was requested before a timestamp authority was attached to the document.'
            );
        }

        return $this->timestampAuthority->post($request);
    }
}
