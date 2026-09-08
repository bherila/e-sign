<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Delivery\Mail\MailContext;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Shared shape for every transactional message.
 *
 * A Mailable here is a renderer and nothing else. It gets one MailContext, it renders one
 * Markdown view, and it neither queues itself nor decides who it is addressed to — the
 * outbox job owns queueing and the sender sets the recipient, so there is exactly one place
 * that knows how a message is delivered and one row that records that it was.
 *
 * Requirements are declared per subclass and checked in this constructor. That matters for
 * more than tidiness: MailOutbox builds the Mailable at enqueue time to learn its subject,
 * so a message with no signing link is rejected in the caller's stack trace instead of
 * being discovered by a queue worker, three retries later, as a blank paragraph that has
 * already been sent.
 *
 * The templates carry no images, no remote stylesheets, and no tracking pixel: a
 * transactional mail about an agreement should not also report when the agreement was read,
 * and a message that fetches nothing renders identically in a client with remote content
 * blocked.
 *
 * The context reaches the view as a MailCopy, never as the MailContext itself, because Blade
 * escapes HTML and these are Markdown templates — see MailCopy for what that lets an
 * untrusted decline reason do otherwise. The property below is protected for that reason and
 * not merely for tidiness: Mailable exposes every *public* property to the view and it wins
 * over anything passed through `with()`, so a public `$context` would silently put the
 * unescaped object back in the template's hands.
 */
abstract class OutboundMailable extends Mailable
{
    /** Beyond this the subject is truncated; mail clients elide it anyway. */
    private const MAX_SUBJECT_TITLE_LENGTH = 120;

    public function __construct(protected readonly MailContext $context)
    {
        $missing = $context->missing($this->requiredFields());

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot be rendered: the mail context is missing %s.',
                static::class,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * MailContext field names this template cannot render without.
     *
     * @return string[]
     */
    abstract protected function requiredFields(): array;

    /**
     * The subject, derived from the context so the value stored on `outbound_mails.subject`
     * is the value that goes out rather than a second copy that can drift from it.
     *
     * Built from the raw context, not the escaped copy: a subject is a header, never
     * Markdown, and backslashes added for CommonMark's benefit would be read literally by
     * every mail client.
     */
    abstract public function subjectLine(): string;

    /** Blade view under resources/views/mail, rendered as Markdown mail. */
    abstract protected function markdownView(): string;

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(
            markdown: $this->markdownView(),
            with: [
                'context' => MailCopy::from($this->context),
                'brand' => $this->brand(),
            ],
        );
    }

    /**
     * The deployment's name, from APP_NAME. Branding is a setting, not a constant: this is
     * self-hosted software and the installation is not called "eSign" everywhere.
     */
    protected function brand(): string
    {
        $brand = trim((string) config('app.name'));

        return $brand === '' ? 'eSign' : $brand;
    }

    protected function title(): string
    {
        return Str::limit($this->context->agreementTitle, self::MAX_SUBJECT_TITLE_LENGTH);
    }
}
