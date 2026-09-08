<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

/**
 * Who wrote a field value.
 *
 * Kept separate from the field's owning recipient, because the two differ routinely: a
 * sender prefills a read-only field that a recipient can see but never edit, and the
 * evidence has to say which of them supplied the text.
 */
enum ValueSource: string
{
    /** The sending workspace: a prefill, or a correction made while the content was still open. */
    case Sender = 'sender';

    /** The field's owning recipient, typed in their own session. */
    case Recipient = 'recipient';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
