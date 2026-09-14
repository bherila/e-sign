<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use Illuminate\Support\Str;

/**
 * The placeholder contact details of an account created for a provider subject before its first
 * sign-in.
 *
 * `esign:bootstrap-owner` creates one for a first owner, and delegated access (#111) creates one
 * when the identity provider provisions somebody. Both use this, so the placeholder address is
 * derived one way everywhere. The real name and address arrive from the provider at first sign-in.
 */
final class PendingAccount
{
    /** A name to show until the provider supplies the real one. */
    public static function name(string $label, string $subject): string
    {
        return $label.' ('.Str::limit($subject, 40, '…').')';
    }

    /**
     * `.invalid` is reserved by RFC 2606 and resolves nowhere, so this address can never be mailed
     * and can never be mistaken for a real one. It is deterministic in the tuple, and it is not an
     * account-linking key: the (issuer, subject) binding is.
     */
    public static function email(string $issuer, string $subject): string
    {
        return 'sso-'.substr(hash('sha256', $issuer."\0".$subject), 0, 32).'@invalid';
    }
}
