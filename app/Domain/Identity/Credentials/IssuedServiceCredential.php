<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials;

/**
 * The one and only moment a plaintext secret exists.
 *
 * `ServiceCredentialIssuer::issue()` and `::rotate()` return this; nothing stores `$secret`,
 * nothing logs it, and no second copy is derivable from the database. A caller that loses it
 * rotates, it does not recover.
 */
final readonly class IssuedServiceCredential
{
    /**
     * @param  ServiceCredential  $credential  The persisted row (its secret columns are digests).
     * @param  string  $secret  The plaintext to show the operator exactly once.
     * @param  ServiceCredential|null  $replaced  On a rotation, the predecessor, whose
     *                                            `expires_at` now marks the end of the
     *                                            overlap window.
     */
    public function __construct(
        public ServiceCredential $credential,
        public string $secret,
        public ?ServiceCredential $replaced = null,
    ) {}
}
