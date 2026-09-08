<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Delivery\Mail\MailContext;
use App\Domain\Delivery\Mail\MailKind;
use Carbon\CarbonImmutable;

/**
 * Synthetic contexts for every mail kind.
 *
 * Names, titles, and hosts are invented and the domains are `.test`, which RFC 6761
 * reserves and no resolver will ever answer, so a fixture that leaks into a real mailer has
 * nowhere to go. AGENTS.md requires this: no real agreements, names, or addresses in the
 * repository.
 *
 * `SIGNING_URL` is the string the rendering tests count. It has exactly one query
 * parameter, which is the most a transactional mail from this product is allowed to carry,
 * and no `&` — an ampersand would be HTML-escaped in an href and would make a
 * substring count of the raw URL meaningless.
 */
final class SyntheticMailContext
{
    public const SIGNING_URL = 'https://esign.example.test/sign/01JQZX9K7M4N2P5R8T3V6W1Y0B?t=cmFuZG9tLW9wYXF1ZQ';

    public const DOWNLOAD_URL = 'https://esign.example.test/agreements/01JQZX9K7M4N2P5R8T3V6W1Y0B/download';

    public static function for(MailKind $kind): MailContext
    {
        return match ($kind) {
            MailKind::Invitation, MailKind::Reminder => new MailContext(
                recipientName: 'Avery Counterparty',
                senderName: 'Example Holdings',
                agreementTitle: 'Mutual Nondisclosure Agreement',
                actionUrl: self::SIGNING_URL,
                expiresAt: CarbonImmutable::parse('2026-10-01T12:00:00Z'),
            ),

            MailKind::Declined => new MailContext(
                recipientName: 'Blake Sender',
                senderName: 'Example Holdings',
                agreementTitle: 'Mutual Nondisclosure Agreement',
                actorName: 'Avery Counterparty',
                reason: 'The signatory named in the document has left the organisation.',
            ),

            MailKind::Cancelled => new MailContext(
                recipientName: 'Avery Counterparty',
                senderName: 'Example Holdings',
                agreementTitle: 'Mutual Nondisclosure Agreement',
                actorName: 'Blake Sender',
                reason: 'Superseded by a revised draft.',
            ),

            MailKind::Expired => new MailContext(
                recipientName: 'Avery Counterparty',
                senderName: 'Example Holdings',
                agreementTitle: 'Mutual Nondisclosure Agreement',
                expiresAt: CarbonImmutable::parse('2026-10-01T12:00:00Z'),
            ),

            MailKind::Completed => new MailContext(
                recipientName: 'Avery Counterparty',
                senderName: 'Example Holdings',
                agreementTitle: 'Mutual Nondisclosure Agreement',
                actionUrl: self::DOWNLOAD_URL,
            ),

            MailKind::Otp => new MailContext(
                recipientName: 'Avery Counterparty',
                senderName: 'Example Holdings',
                agreementTitle: 'Mutual Nondisclosure Agreement',
                expiresAt: CarbonImmutable::parse('2026-10-01T12:10:00Z'),
                // Synthetic and fixed, so the rendering assertions are deterministic. A real
                // code is six digits from random_int and never appears in a fixture.
                otpCode: '135790',
            ),

            MailKind::AdminFailure => new MailContext(
                recipientName: 'Operations',
                failureSummary: 'Finalization could not produce a validated sealed PDF.',
                reference: 'envelope 01JQZX9K7M4N2P5R8T3V6W1Y0B',
                reason: 'The timestamp authority did not answer within the configured timeout.',
            ),
        };
    }

    /** The one URL a given kind's template is expected to render, if any. */
    public static function urlFor(MailKind $kind): ?string
    {
        return self::for($kind)->actionUrl;
    }
}
