<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\InvitationIssuer;
use App\Domain\Signing\Sessions\IssuedInvitation;
use App\Domain\Signing\Sessions\SigningCookie;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * A sent envelope, a document with real bytes behind it, and an invitation to sign it.
 *
 * {@see SigningScenario} builds rows and no bytes, which is right for the state machine and
 * wrong here: the guest surface streams the review revision to PDF.js, so a test of it needs
 * something on the disk. This wraps that scenario and adds the three things the HTTP surface
 * needs — bytes on a faked disk, page geometry in the document's preflight report, and a
 * credential — without changing what the state-machine suite depends on.
 *
 * `tokenOf()` is the reason a test can reach the landing page at all. The plaintext token
 * exists once, in the URL `InvitationIssuer` returns, and is never stored; parsing it back
 * out of that URL is exactly what a mail client does and is the only way anything gets it.
 *
 * All names, addresses, titles, and bytes are synthetic (AGENTS.md).
 */
final class GuestSigningScenario
{
    /** Not a real PDF. Nothing in the guest surface parses it server-side; it is streamed. */
    public const DOCUMENT_BYTES = "%PDF-1.7\n% synthetic review revision for the guest signing suite\n%%EOF\n";

    public readonly SigningScenario $signing;

    public readonly Envelope $envelope;

    private function __construct(SigningScenario $signing, Envelope $envelope)
    {
        $this->signing = $signing;
        $this->envelope = $envelope;
    }

    /**
     * A sent envelope whose first stage is active, ready for a guest to be invited.
     *
     * @param  array<string, mixed>  $overrides  Passed through to the envelope snapshot.
     */
    public static function sent(array $overrides = []): self
    {
        Storage::fake('documents');

        $signing = SigningScenario::create()->bind();
        $envelope = $signing->sent($overrides);

        // The bytes the signing page will stream. Written straight to the faked disk at the
        // path the revision row already claims, because the revision is a row-level fixture
        // and re-running intake here would test the wrong module.
        Storage::disk('documents')->put($signing->revision->path, self::DOCUMENT_BYTES);

        // Page geometry, in the shape the preflight report records and the coordinate space
        // defines: A4 portrait, no rotation, CropBox at the origin.
        $signing->document->forceFill([
            'page_count' => 2,
            'preflight_report' => [
                'accepted' => true,
                'findings' => [],
                'metrics' => [],
                'pages' => [
                    self::page(1),
                    self::page(2),
                ],
            ],
        ])->save();

        return new self($signing, $envelope);
    }

    public function recipient(string $schemaRecipientId = 'buyer'): EnvelopeRecipient
    {
        return $this->signing->recipient($this->envelope, $schemaRecipientId);
    }

    /** Issue a credential the way the sender's own code would. */
    public function invite(?EnvelopeRecipient $recipient = null, ?int $ttlHours = null): IssuedInvitation
    {
        return app(InvitationIssuer::class)->issue($recipient ?? $this->recipient(), $ttlHours);
    }

    /**
     * The plaintext token out of an issued URL.
     *
     * The only place a test can obtain one, for the same reason a recipient's mail client is
     * the only place a person can: nothing on the server keeps it.
     */
    public static function tokenOf(IssuedInvitation $issued): string
    {
        $path = (string) parse_url($issued->url, PHP_URL_PATH);
        $segments = explode('/', trim($path, '/'));
        $token = end($segments);

        if ($token === false || $token === '') {
            throw new InvalidArgumentException('An issued invitation URL had no token segment.');
        }

        return $token;
    }

    /** The landing URL for an invitation, as it would appear in the mail. */
    public static function landingUrl(IssuedInvitation $issued): string
    {
        return (string) parse_url($issued->url, PHP_URL_PATH);
    }

    /** The POST target behind the Continue button. */
    public static function startUrl(Envelope $envelope, string $token): string
    {
        return '/sign/'.$envelope->public_id.'/'.$token.'/start';
    }

    public static function sessionUrl(Envelope $envelope, string $suffix = ''): string
    {
        return '/sign/'.$envelope->public_id.'/session'.$suffix;
    }

    /** The signing cookie's name, so a test does not retype it. */
    public static function cookieName(): string
    {
        return SigningCookie::NAME;
    }

    /**
     * @return array<string, mixed>
     */
    private static function page(int $number): array
    {
        return [
            'page' => $number,
            'crop_box' => [0.0, 0.0, 595.276, 841.89],
            'rotation' => 0,
            'user_unit' => 1.0,
            'native_width' => 595.276,
            'native_height' => 841.89,
        ];
    }
}
