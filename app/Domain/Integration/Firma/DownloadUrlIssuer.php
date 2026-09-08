<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * Mints and reads the short-lived URL the facade returns wherever upstream returns a
 * pre-signed storage URL.
 *
 * ## Why a URL at all, when the native API has none
 *
 * The native API hands out no URLs: a caller fetches bytes from a route, authenticated with
 * its own credential, every time. That is strictly better and it is what
 * `docs/BLOB_STORAGE.md` rule 1 asks for. But this profile's contract is a JSON body with a
 * `download_url` in it and an `expires_at` beside it, and a consumer written against it
 * follows that link — often from a browser, or a worker that holds no API key. Returning
 * null there would make `/download` useless; returning the API route would make it fail for
 * the client that most needs it.
 *
 * So the facade issues a URL that is a **capability**, and narrows it in the three ways that
 * matter:
 *
 * 1. **It is ours, not the object store's.** The bytes still stream through this application
 *    on every request, on every driver. Nothing is pre-signed, no disk name or object path
 *    appears in the link, and revoking a disk's credentials does not leave live links behind.
 * 2. **It expires in fifteen minutes**, not upstream's hour
 *    ({@see FirmaProfile::DOWNLOAD_URL_TTL_MINUTES}), and the expiry is signed rather than
 *    advisory: the timestamp is inside the signature, so it cannot be extended by editing
 *    the query string.
 * 3. **It names one document of one signing request.** The token carries the envelope's
 *    public id and which of the two retained objects is meant, and nothing else — no
 *    workspace, no credential, no scope. Tampering with either changes the signature, and
 *    the route re-resolves the envelope from the id rather than trusting anything else in
 *    the link.
 *
 * What this deliberately is **not** is a bearer token for the API. It cannot list, patch,
 * send, or cancel anything, and it cannot reach a second agreement.
 *
 * A webhook body never carries one of these. A body is encoded once and replayed for up to
 * ~40 h, so a fifteen-minute link in it is dead before most receivers read it and a longer
 * one is a credential sitting in somebody's log — which is why
 * App\Domain\Delivery\Events\SigningRequestPayload reports only *that* an artifact exists.
 */
final class DownloadUrlIssuer
{
    /** The executed, sealed PDF. Only exists once the envelope has completed. */
    public const EXECUTED = 'executed';

    /**
     * The reviewed revision: the bytes every party was actually shown.
     *
     * This is what `/download` returns with `is_partial: true` for a request that is still
     * out, or was cancelled, declined, or expired. The recorded fixtures show upstream doing
     * the same thing — HTTP 200, `is_partial: true`, and a document-only URL — rather than
     * erroring (`tests/Fixtures/firma/firma-compat-v1/README.md`).
     */
    public const REVIEW = 'review';

    /**
     * The completion report: a one-page statement of who signed, when, at what assurance
     * level, under which seal key.
     *
     * Upstream calls this member `certificate_only_download_url`. The field name is kept so
     * a consumer can read it; the document itself is a report and is described as one
     * everywhere a human sees it, never as a credential belonging to a signer
     * (AGENTS.md, "Honest language").
     */
    public const REPORT = 'report';

    /** Every kind a token may name. */
    public const KINDS = [self::EXECUTED, self::REVIEW, self::REPORT];

    /** Route name of the streaming endpoint. */
    public const ROUTE = 'compat.firma.downloads.show';

    /** Token format version, so a later format is distinguishable rather than ambiguous. */
    private const VERSION = 'v1';

    /**
     * The expiry every URL issued in one response shares.
     *
     * Taken once by the caller and passed in, so a body whose `expires_at` says one thing
     * and whose three URLs expire a millisecond apart cannot happen.
     */
    public function expiresAt(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->addMinutes(FirmaProfile::DOWNLOAD_URL_TTL_MINUTES);
    }

    /**
     * @param  string  $kind  One of {@see KINDS}.
     */
    public function urlFor(Envelope $envelope, string $kind, CarbonImmutable $expiresAt): string
    {
        return URL::temporarySignedRoute(self::ROUTE, $expiresAt, [
            'token' => $this->encode($envelope, $kind),
        ]);
    }

    /**
     * @param  string  $kind  One of {@see KINDS}.
     */
    public function encode(Envelope $envelope, string $kind): string
    {
        return rtrim(strtr(base64_encode(implode('.', [
            self::VERSION,
            $envelope->public_id,
            $kind,
        ])), '+/', '-_'), '=');
    }

    /**
     * @return array{envelope: string, kind: string}
     *
     * @throws FirmaException When the token is not one this service issued.
     */
    public function decode(string $token): array
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $parts = $decoded === false ? [] : explode('.', $decoded);

        if (count($parts) !== 3
            || $parts[0] !== self::VERSION
            || $parts[1] === ''
            || ! in_array($parts[2], self::KINDS, true)) {
            // The signature has already been checked by the time this runs, so a token that
            // does not parse means this service issued it under a different format. Same
            // answer as a link to something that no longer exists.
            throw FirmaException::notFound('download');
        }

        return ['envelope' => $parts[1], 'kind' => $parts[2]];
    }
}
