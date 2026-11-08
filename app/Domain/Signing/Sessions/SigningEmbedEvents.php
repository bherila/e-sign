<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use Illuminate\Contracts\Config\Repository;

/**
 * The origins a signing page may announce its progress to, and the attribute that tells it so.
 *
 * The event contract is `docs/signing/embedded-events.md` (issue #168). The page posts each
 * message with `postMessage(message, origin)` to its framing parent or, in a popup, its opener
 * — once per configured origin, never to `*`, so only a page actually served from an allowed
 * origin can receive it. With no origin configured, which is the default, the page carries no
 * attribute and loads no script, and nothing is posted anywhere.
 *
 * An origin is `scheme://host[:port]` exactly, as a browser reports `event.origin`: HTTPS
 * only, except `http://localhost` and `http://127.0.0.1` for development. Anything else in
 * `esign.signing.embed_origins` — a path, a wildcard, a trailing slash, credentials — is
 * dropped rather than guessed at, because a near-miss origin is a message sent nowhere and a
 * loose one is a message sent to somebody else.
 *
 * Announcing progress does not make the page frameable. `SigningSecurityHeaders` still sends
 * `frame-ancestors 'none'`; whether a deployment may frame its signing pages is a separate
 * decision, and until it is made the contract is usable from a popup.
 */
final readonly class SigningEmbedEvents
{
    public function __construct(private Repository $config) {}

    /** @return list<string> */
    public function origins(): array
    {
        $configured = $this->config->get('esign.signing.embed_origins', []);
        $candidates = is_array($configured) ? $configured : explode(',', (string) $configured);
        $origins = [];

        foreach ($candidates as $candidate) {
            $origin = is_string($candidate) ? trim($candidate) : '';

            if (self::isOrigin($origin)) {
                $origins[] = strtolower($origin);
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * The `data-signing-events` value for a page, or null when nothing may be announced.
     *
     * @param  array<string, mixed>|null  $event  The event this page itself stands for, if any.
     */
    public function attribute(?array $event): ?string
    {
        $origins = $this->origins();

        if ($origins === []) {
            return null;
        }

        return json_encode(['origins' => $origins, 'event' => $event], JSON_THROW_ON_ERROR);
    }

    public static function isOrigin(string $origin): bool
    {
        if (preg_match('#^(https)://[a-z0-9.-]+(:[0-9]{1,5})?$#iD', $origin) === 1) {
            return true;
        }

        return preg_match('#^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$#iD', $origin) === 1;
    }
}
