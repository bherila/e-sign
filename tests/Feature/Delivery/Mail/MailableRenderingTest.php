<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Mail;

use App\Domain\Delivery\Mail\MailContext;
use App\Domain\Delivery\Mail\MailKind;
use App\Mail\CompletedMail;
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
        $links = array_values(array_filter(
            $this->hrefsIn($rendered),
            static fn (string $href): bool => rtrim($href, '/') !== rtrim((string) config('app.url'), '/'),
        ));

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
     * @return string[]
     */
    private function hrefsIn(string $rendered): array
    {
        preg_match_all('/href="([^"]*)"/i', $rendered, $matches);

        return $matches[1];
    }
}
