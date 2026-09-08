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
     * syntax. `!` and the rest of ordinary punctuation stay readable, because Laravel renders
     * the plain-text alternative from the same Blade view *without* a Markdown pass, so every
     * escape added here is a backslash a human reads.
     *
     * Block markers are handled separately, by {@see neutralizeBlockStarts()}, and only where
     * they begin a line. "Can change block formatting at worst" understated it: a decline
     * reason is typed by an external signer, is rendered inside a `> ` blockquote, and may
     * contain newlines, so `Wrong signatory.\n\n# Your agreement was suspended\n\nCall …`
     * escaped the quote and forged an H1 in a notice the sender receives from their own
     * agreement service (docs/security/review-2026-09.md finding D-6). No link can be
     * injected either way; a forged heading is a phishing frame, which is enough.
     *
     * Bare URLs are not links: Laravel's mail Markdown environment loads only the CommonMark
     * core and table extensions, with no autolink extension, so `https://evil.test` in a
     * reason renders as text. That is asserted in MailableRenderingTest rather than assumed.
     */
    private const MARKDOWN_METACHARACTERS = ['\\', '[', ']', '`', '*', '_', '~', '|'];

    /**
     * Line-leading markers that open a block, matched only at the start of a line.
     *
     * `#` heading, `>` blockquote, `-`/`+` bullet and setext underline, `=` setext underline,
     * `1.`/`1)` ordered list. Escaping them anywhere would put backslashes into every ordinary
     * sentence containing a hyphen; escaping them only where CommonMark would read them as a
     * block start leaves prose alone.
     */
    private const BLOCK_START_PATTERN = '/^(\s*)(?:([#>+=-])|(\d{1,9})([.)]))/m';

    public readonly string $recipientName;

    public readonly string $senderName;

    public readonly string $agreementTitle;

    public readonly ?string $actorName;

    public readonly ?string $reason;

    public readonly ?string $failureSummary;

    public readonly ?string $reference;

    public readonly ?string $actionUrl;

    public readonly ?CarbonImmutable $expiresAt;

    /**
     * The one-time code for a guest signing session.
     *
     * Escaped like every other text field even though it cannot need it — the code is six
     * digits this application generated with `random_int`, so there is nothing in it for
     * CommonMark to interpret. Routing it through the same escape as the untrusted fields
     * costs nothing and means a future change to how a code is formed cannot quietly become
     * the one interpolation that was not neutralized.
     */
    public readonly ?string $otpCode;

    private function __construct(MailContext $context)
    {
        $this->recipientName = self::escape($context->recipientName) ?? '';
        $this->senderName = self::escape($context->senderName) ?? '';
        $this->agreementTitle = self::escape($context->agreementTitle) ?? '';
        $this->actorName = self::escape($context->actorName);
        $this->reason = self::escape($context->reason);
        $this->failureSummary = self::escape($context->failureSummary);
        $this->reference = self::escape($context->reference);
        $this->otpCode = self::escape($context->otpCode);

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

        return self::neutralizeBlockStarts($text);
    }

    /**
     * Stop a line inside a quoted field from starting a new block.
     *
     * A backslash before the marker is CommonMark's own escape, so the character renders as
     * itself and the line stays part of the paragraph — and the blockquote — it was put in.
     *
     * An ordered list is escaped on its *delimiter*, not its digits: CommonMark only honours
     * a backslash before ASCII punctuation, so `\1.` would render the backslash literally
     * while `1\.` both suppresses the list and reads as typed.
     */
    private static function neutralizeBlockStarts(string $text): string
    {
        return preg_replace_callback(
            self::BLOCK_START_PATTERN,
            static fn (array $m): string => ($m[2] ?? '') !== ''
                ? $m[1].'\\'.$m[2]
                : $m[1].$m[3].'\\'.$m[4],
            $text,
        ) ?? $text;
    }
}
