<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

/**
 * What a party does on an agreement (field schema 1.2, issue #169).
 *
 * - **signer** — signs. The default, and what every party was before 1.2.
 * - **approver** — acts in the signing order and records an approval, never a signature. Owns
 *   the `approval_*` field types and may not own `signature` or `initials`.
 * - **cc** — is copied: receives the completed agreement, owns no fields, and has no place in
 *   `signing_order`.
 *
 * At least one signer is required.
 */
enum RecipientDesignation: string
{
    case Signer = 'signer';
    case Approver = 'approver';
    case Cc = 'cc';

    public const DEFAULT = self::Signer;

    /** The first schema minor that declares `recipients[].designation`. */
    public const SINCE_MINOR = 2;

    /** Whether this party acts in the signing order. */
    public function acts(): bool
    {
        return $this !== self::Cc;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
