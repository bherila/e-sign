<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Mail;

use App\Domain\Delivery\Mail\MailContext;
use App\Domain\Delivery\Mail\MailKind;
use App\Mail\CompletedMail;
use App\Mail\DeclinedMail;
use App\Mail\InvitationMail;
use App\Mail\OutboundMailable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticMailContext;
use Tests\TestCase;

/**
 * Every template renders from a synthetic context, and the one that carries a link carries
 * it exactly once.
 *
 * "Exactly once" is not tidiness. A transactional mail is forwarded, quoted, archived, and
 * fetched by a scanner, and each additional copy of a credential-bearing URL is another
 * place it can be lifted from. It is also a regression guard against the obvious
 * well-meant edit: adding a "if the button does not work, paste this link" paragraph, which
 * doubles the token in the message.
 */
class MailableRenderingTest extends TestCase
{
    /**
     * @return array<string, array{MailKind}>
     */
    public static function kinds(): array
    {
        $cases = [];

        foreach (MailKind::cases() as $kind) {
            $cases[$kind->value] = [$kind];
        }

        return $cases;
    }

    #[DataProvider('kinds')]
    public function test_renders_with_a_synthetic_context(MailKind $kind): void
    {
        $context = SyntheticMailContext::for($kind);
        $rendered = $kind->mailable($context)->render();

        $this->assertNotSame('', trim($rendered));

        if ($kind === MailKind::AdminFailure) {
            // Operator mail is addressed to a role rather than a person, so it states the
            // problem and the reference instead of a name.
            $this->assertStringContainsString((string) $context->failureSummary, $rendered);
            $this->assertStringContainsString((string) $context->reference, $rendered);

            return;
        }

        $this->assertStringContainsString($context->recipientName, $rendered);
        $this->assertStringContainsString($context->agreementTitle, $rendered);
    }

    #[DataProvider('kinds')]
    public function test_renders_its_action_url_exactly_once(MailKind $kind): void
    {
        $url = SyntheticMailContext::urlFor($kind);

        $rendered = $kind->mailable(SyntheticMailContext::for($kind))->render();

        // The framework's mail header links the brand name at APP_URL. That is a link to
        // the application root and carries nothing, so it is excluded rather than counted.
        $links = $this->foreignLinksIn($rendered);

        if ($url === null) {
            // A template with no link must have no link. Decline, cancellation, and
            // operator mail carry no credential, so any other href in one would be a token
            // this product decided not to send.
            $this->assertSame([], $links, "The {$kind->value} template is not supposed to link anywhere.");

            return;
        }

        $this->assertSame([$url], $links, "The {$kind->value} template must link to its action URL and nothing else.");
        $this->assertSame(1, substr_count($rendered, $url), "The {$kind->value} template rendered its action URL more than once.");
    }

    #[DataProvider('kinds')]
    public function test_carries_no_remote_asset_or_tracking_pixel(MailKind $kind): void
    {
        $rendered = $kind->mailable(SyntheticMailContext::for($kind))->render();

        $this->assertStringNotContainsString('<img', $rendered);
        $this->assertStringNotContainsString('<script', $rendered);
        // The framework's mail theme is inlined CSS; nothing may be fetched at open time.
        $this->assertStringNotContainsString('<link', $rendered);
    }

    #[DataProvider('kinds')]
    public function test_subject_is_derived_from_the_context(MailKind $kind): void
    {
        $mailable = $kind->mailable(SyntheticMailContext::for($kind));

        $this->assertNotSame('', trim($mailable->subjectLine()));
        $this->assertSame($mailable->subjectLine(), $mailable->envelope()->subject);
    }

    public function test_invitation_states_the_expiry_it_was_given(): void
    {
        $rendered = (new InvitationMail(SyntheticMailContext::for(MailKind::Invitation)))->render();

        $this->assertStringContainsString('1 Oct 2026', $rendered);
        $this->assertStringContainsString('Mutual Nondisclosure Agreement', $rendered);
        $this->assertStringContainsString('Example Holdings', $rendered);
    }

    public function test_invitation_refuses_to_be_built_without_a_link(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/actionUrl/');

        new InvitationMail(new MailContext(
            recipientName: 'Avery Counterparty',
            senderName: 'Example Holdings',
            agreementTitle: 'Mutual Nondisclosure Agreement',
        ));
    }

    public function test_completion_renders_without_a_download_url_and_never_attaches_the_pdf(): void
    {
        $mailable = new CompletedMail(new MailContext(
            recipientName: 'Avery Counterparty',
            agreementTitle: 'Mutual Nondisclosure Agreement',
        ));

        $rendered = $mailable->render();

        $this->assertStringContainsString('Mutual Nondisclosure Agreement', $rendered);
        // Nothing is attached, ever: the executed PDF is served from the application, where
        // the request is authorized each time. See App\Mail\CompletedMail.
        $this->assertSame([], $mailable->attachments);
        $this->assertSame([], $mailable->rawAttachments);
    }

    /**
     * @return array<string, array{MailKind}>
     */
    public static function kindsWithFreeText(): array
    {
        return [
            'declined' => [MailKind::Declined],
            'cancelled' => [MailKind::Cancelled],
            'admin_failure' => [MailKind::AdminFailure],
        ];
    }

    #[DataProvider('kindsWithFreeText')]
    public function test_markdown_in_a_free_text_field_cannot_inject_a_link(MailKind $kind): void
    {
        // Blade escapes HTML, not Markdown, and these are Markdown mailables. A decline
        // reason is typed by an external signer holding nothing but a signing link, and the
        // notice goes to the sender — who has every reason to trust a link in a message
        // from their own agreement service. See App\Mail\MailCopy.
        $context = new MailContext(
            recipientName: 'Blake Sender',
            senderName: 'Example Holdings',
            agreementTitle: 'Mutual Nondisclosure Agreement',
            actorName: 'Avery Counterparty',
            reason: "I declined.\n\n[Restore this agreement](https://evil.test/phish)",
            failureSummary: "Finalization failed.\n\n[Retry now](https://evil.test/phish)",
            reference: '[envelope](https://evil.test/phish)',
        );

        $rendered = $kind->mailable($context)->render();

        $this->assertSame([], $this->foreignLinksIn($rendered));
        // The text still reaches the reader; only its link syntax is inert.
        $this->assertStringContainsString('Restore this agreement', $rendered);
    }

    #[DataProvider('kindsWithFreeText')]
    public function test_markdown_in_a_name_or_title_cannot_inject_a_link(MailKind $kind): void
    {
        $context = new MailContext(
            recipientName: '[Blake](https://evil.test/one)',
            senderName: '[Example](https://evil.test/two)',
            agreementTitle: '![NDA](https://evil.test/three)',
            actorName: '[Avery][ref]',
            reason: 'Fine.',
            failureSummary: 'Failed.',
            reference: 'envelope 01JQZX',
        );

        $this->assertSame([], $this->foreignLinksIn($kind->mailable($context)->render()));
    }

    public function test_a_bare_url_in_free_text_is_not_turned_into_a_link(): void
    {
        // Asserted rather than assumed: Laravel's mail Markdown environment loads only the
        // CommonMark core and table extensions, with no autolink extension. If that ever
        // changes, MailCopy has to grow a rule for bare URLs and this test says so.
        $rendered = (new DeclinedMail(new MailContext(
            recipientName: 'Blake Sender',
            agreementTitle: 'Mutual Nondisclosure Agreement',
            actorName: 'Avery Counterparty',
            reason: 'See https://evil.test/bare and <https://evil.test/auto>.',
        )))->render();

        $this->assertSame([], $this->foreignLinksIn($rendered));
    }

    public function test_the_escaped_copy_never_reaches_the_subject_line(): void
    {
        // A subject is a header, never Markdown. Backslashes added for CommonMark's benefit
        // would be read literally by every mail client.
        $mailable = new InvitationMail(new MailContext(
            recipientName: 'Avery Counterparty',
            senderName: 'Smith_Jones & Co [Holdings]',
            agreementTitle: 'Mutual NDA*',
            actionUrl: SyntheticMailContext::SIGNING_URL,
        ));

        $this->assertStringContainsString('Smith_Jones & Co [Holdings]', $mailable->subjectLine());
        $this->assertStringNotContainsString('\\', $mailable->subjectLine());
    }

    public function test_branding_comes_from_the_app_name(): void
    {
        config()->set('app.name', 'Example Agreements');

        $rendered = $this->kindMailable(MailKind::Completed)->render();

        $this->assertStringContainsString('Example Agreements', $rendered);
    }

    private function kindMailable(MailKind $kind): OutboundMailable
    {
        return $kind->mailable(SyntheticMailContext::for($kind));
    }

    /**
     * Every link in the message other than the mail header's link to APP_URL.
     *
     * @return string[]
     */
    private function foreignLinksIn(string $rendered): array
    {
        return array_values(array_filter(
            $this->hrefsIn($rendered),
            static fn (string $href): bool => rtrim($href, '/') !== rtrim((string) config('app.url'), '/'),
        ));
    }

    /**
     * @return string[]
     */
    private function hrefsIn(string $rendered): array
    {
        preg_match_all('/href="([^"]*)"/i', $rendered, $matches);

        return $matches[1];
    }
}
