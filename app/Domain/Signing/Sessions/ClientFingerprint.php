<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use Illuminate\Http\Request;

/**
 * What one request says about the client it came from, in the two forms this module needs.
 *
 * The *hashed* form goes on the session row and answers a corroboration question — "is this
 * the place the session started from?" — without the table accumulating a browsing history.
 * The *plain* form goes into `recipient_attestations.client_evidence` exactly once, at the
 * moment of assent, because docs/HANDOFF.md section 8 asks the evidence record to capture
 * appropriately minimized network evidence and a hash corroborates nothing to a later
 * reader. The same section is equally clear that neither form is identity proof, and nothing
 * in this module treats them as one: a mismatch is recorded, never used to refuse.
 *
 * `Request::ip()` is the address Laravel resolved, which behind a reverse proxy means
 * whatever `TrustProxies` was configured to believe. An unconfigured deployment therefore
 * records its load balancer, and that is the honest outcome — inventing an address from an
 * untrusted `X-Forwarded-For` would put an attacker-chosen string into an evidence record.
 *
 * The user agent is truncated to the width `ClientEvidence` keeps, in the same place, so the
 * hashed value and the recorded value are digests and copies of the same string rather than
 * of two different truncations.
 */
final readonly class ClientFingerprint
{
    private function __construct(
        public string $ip,
        public string $userAgent,
        public string $ipHash,
        public string $userAgentHash,
    ) {}

    public static function of(Request $request, KeyedDigest $digest): self
    {
        $ip = (string) ($request->ip() ?? '');
        $userAgent = mb_substr((string) $request->userAgent(), 0, 255);

        return new self(
            $ip,
            $userAgent,
            $digest->of('signing.ip', $ip),
            $digest->of('signing.user_agent', $userAgent),
        );
    }

    /**
     * The subset of `ClientEvidence::ALLOWED_KEYS` a signing page can honestly fill in.
     *
     * `channel` is a fixed label rather than anything the browser said, so the evidence
     * record states which surface took the assent without trusting the surface to say so.
     *
     * @return array<string, string>
     */
    public function asClientEvidence(Request $request, string $channel): array
    {
        return array_filter([
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'accept_language' => mb_substr((string) $request->header('Accept-Language', ''), 0, 255),
            'channel' => $channel,
        ], static fn (string $value): bool => $value !== '');
    }
}
