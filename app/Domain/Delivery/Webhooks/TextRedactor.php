<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

/**
 * Strips anything that looks like a credential out of text we are about to
 * store or log, then truncates it.
 *
 * A receiver's error page routinely echoes the request it did not like,
 * headers included, and a transport error message can contain a URL with a
 * query-string token. Neither may end up in `webhook_deliveries` or in the log,
 * so response excerpts and error strings pass through here first. The rules are
 * deliberately over-broad: losing a diagnostic detail is cheaper than retaining
 * a credential.
 */
final class TextRedactor
{
    public const PLACEHOLDER = '[redacted]';

    /**
     * @var list<string>
     */
    private const PATTERNS = [
        // JSON Web Tokens, whole.
        '/eyJ[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]*)?/',
        // Authorization-style schemes.
        '/\b(?:Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=-]{8,}/i',
        // key: value and key=value where the key names a credential. The key is
        // kept so the shape of the response is still readable.
        '/\b(authorization|api[_-]?key|apikey|access[_-]?token|refresh[_-]?token|id[_-]?token|token|secret|client[_-]?secret|password|passwd|passphrase|signature|whsec)\b(\s*[:=]\s*)"?[^\s",;}\]]+/i',
        // Any long opaque run: hex digests, base64url blobs, session ids. ULIDs
        // are 26 characters and stay readable, which matters because event and
        // attempt identifiers are the thing an operator correlates on.
        '/\b[A-Za-z0-9_-]{32,}\b/',
    ];

    /**
     * @param  list<string>  $literals  Values known to be secret, redacted verbatim.
     */
    public function redact(string $text, array $literals = [], ?int $maxBytes = null): string
    {
        foreach ($literals as $literal) {
            if ($literal !== '') {
                $text = str_replace($literal, self::PLACEHOLDER, $text);
            }
        }

        foreach (self::PATTERNS as $index => $pattern) {
            // The keyed pattern keeps its key and separator; the rest go whole.
            $replacement = $index === 2 ? '$1$2'.self::PLACEHOLDER : self::PLACEHOLDER;
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        // Control characters would make a log line or a console table lie about
        // its own shape.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        // The ellipsis is three bytes, so the cut leaves room for it and the
        // result never exceeds the column the caller sized for.
        if ($maxBytes !== null && strlen($text) > $maxBytes) {
            $text = rtrim(mb_strcut($text, 0, max(1, $maxBytes - 3), 'UTF-8')).'…';
        }

        return $text;
    }
}
