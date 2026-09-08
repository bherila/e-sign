<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compat\Firma;

use App\Domain\Integration\Firma\FirmaException;
use App\Domain\Integration\Firma\FirmaProfile;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Everything else under `/functions/v1/signing-request-api`.
 *
 * The pinned upstream document has 72 paths; profile `firma-compat-v1` implements nine of
 * them. The rest — `/company*`, `/workspaces*`, `/templates*`, `/documents`, webhook
 * administration, the JWT and template-token routes, custom fields, email templates, signer
 * terms, domains, logos, the signing-request *list*, `PUT` and `DELETE` on a signing request,
 * reminders, audit, resend, and the per-signer signature/initials/stamp/file routes — are
 * deliberately out of profile and listed as such in
 * `docs/compatibility/firma-capability-matrix.md`.
 *
 * They answer `501` naming the path. Not `404`: a `404` says "no such endpoint", which would
 * be a lie about a route the upstream contract really does declare, and it would send an
 * integrator hunting for a typo instead of reading the matrix. Not a `200` with a plausible
 * body either — that is the successful no-op AGENTS.md forbids, and on a route like
 * `/signing-requests/{id}/resend` it would mean reporting that a signer had been written to
 * when nobody had.
 *
 * Expanding into any of these families is a deliberate profile change: the rows go into the
 * matrix, with shapes read from the pinned document, before any adapter code is written.
 */
class UnsupportedEndpointController extends Controller
{
    public function __invoke(Request $request): never
    {
        throw FirmaException::unsupported(
            $request->method().' /'.ltrim($request->path(), '/'),
            'This endpoint is declared by the Firma Partner API and is not part of acceptance profile '
            .FirmaProfile::NAME.'. It is refused rather than answered with an empty success, so that a '
            .'caller never records an action this service did not take. See '
            .'docs/compatibility/firma-capability-matrix.md for what is in profile.',
        );
    }
}
