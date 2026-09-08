<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Delivery\Mail\MailContext;
use Carbon\CarbonImmutable;

/**
 * The mail context as a Markdown template may safely interpolate it.
 *
 * Blade escapes HTML; it does not escape Markdown. These are Markdown mailables, so Blade
 * hands its HTML-escaped output to CommonMark, and `[click here](https://evil.test)` in any
 * interpolated string becomes a live anchor in the delivered message. The fields that reach
 * these templates are not trusted: a decline reason is typed by an external signer who holds
 * nothing but a signing link, and the resulting notice goes to the sender — who has every
 * reason to trust a link in a message from their own agreement service.
 *
 * That is why the templates see this object rather than the MailContext. Every text field is
 * neutralized on the way in, so a template cannot interpolate an unescaped one by accident,
 * and the escaping is in one place instead of repeated at every `{{ }}`.
 *
 * `$actionUrl` is deliberately *not* escaped. It is the one URL the message is allowed to
 * carry, MailContext has already validated its shape, and it is only ever used in a Blade
 * attribute (`<x-mail::button :url="...">`), where Blade's HTML escaping is the right and
 * sufficient protection. Escaping it for Markdown would break the link.
 */
final class MailCopy
{
    /**
     * Characters neutralized, and why each one is here:
     *
     *   \      first, or every escape below would itself be escapable
     *   [ ]    inline links, images, and reference links — the actual attack
     *   `      code spans, which would swallow surrounding markup
     *   * _ ~  emphasis and strikethrough
     *   |      table cells; Laravel's mail Markdown enables the table extension
     *
     * Left alone on purpose: `<`, `>`, `&`, `"`, and `'` are already turned into entities by
     * Blade before CommonMark sees them, which is what kills raw HTML and `<autolink>`
     * syntax. `#`, `!`, `-`, `+`, and leading digits can change block formatting at worst,
     * and escaping them would put visible backslashes into ordinary prose — Laravel renders
     * the plain-text alternative from the same Blade view *without* a Markdown pass, so
     * every escape added here is a backslash a human reads.
     *
     * Bare URLs are not links: Laravel's mail Markdown environment loads only the CommonMark
     * core and table extensions, with no autolink extension, so `https://evil.test` in a
     * reason renders as text. That is asserted in MailableRenderingTest rather than assumed.
     */
    private const MARKDOWN_METACHARACTERS = ['\\', '[', ']', '`', '*', '_', '~', '|'];

    public readonly string $recipientName;

    public readonly string $senderName;

    public readonly string $agreementTitle;

    public readonly ?string $actorName;

    public readonly ?string $reason;

    public readonly ?string $failureSummary;

    public readonly ?string $reference;

    public readonly ?string $actionUrl;

    public readonly ?CarbonImmutable $expiresAt;

    private function __construct(MailContext $context)
    {
        $this->recipientName = self::escape($context->recipientName) ?? '';
        $this->senderName = self::escape($context->senderName) ?? '';
        $this->agreementTitle = self::escape($context->agreementTitle) ?? '';
        $this->actorName = self::escape($context->actorName);
        $this->reason = self::escape($context->reason);
        $this->failureSummary = self::escape($context->failureSummary);
        $this->reference = self::escape($context->reference);

        // Not escaped: see the class docblock.
        $this->actionUrl = $context->actionUrl;
        $this->expiresAt = $context->expiresAt;
    }

    public static function from(MailContext $context): self
    {
        return new self($context);
    }

    public static function escape(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        foreach (self::MARKDOWN_METACHARACTERS as $character) {
            $text = str_replace($character, '\\'.$character, $text);
        }

        return $text;
    }
}
