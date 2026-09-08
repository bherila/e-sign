<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

/**
 * How the recipients of one envelope are released.
 *
 * Sequential is the first consumer's case and the default. Parallel exists, and has its own
 * tests, because docs/HANDOFF.md section 6 requires that all material values be collected
 * and frozen *before* anyone can assent in that mode: two people must never accept
 * materially different contract text because a third edited a shared field between them.
 */
enum SigningMode: string
{
    /** One stage of `signing_order` at a time. */
    case Sequential = 'sequential';

    /**
     * Everyone at once. Requires a single-stage `signing_order`; the envelope factory
     * refuses a multi-stage schema in this mode rather than quietly ignoring the order.
     */
    case Parallel = 'parallel';

    /** True when content must be frozen at send rather than at the first acceptance. */
    public function freezesAtSend(): bool
    {
        return $this === self::Parallel;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
