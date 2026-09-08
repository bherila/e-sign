<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

/**
 * The consent policy version presented with an acceptance is not the one the envelope was
 * created under.
 *
 * docs/ARCHITECTURE.md invariant 3: consent is validated server-side and a client flag
 * carries no authority. The recipient's session states which consent text it displayed; if
 * that is not the envelope's version, the acceptance describes a disclosure the person was
 * not actually shown, so it is refused rather than recorded with the server's own version
 * substituted in.
 */
final class ConsentMismatch extends SigningException
{
    public function __construct(
        public readonly string $presented,
        public readonly string $expected,
    ) {
        parent::__construct(sprintf(
            'Consent policy version "%s" was presented, but this envelope was created under "%s"; '
            .'the recipient must review the current consent text.',
            $presented,
            $expected,
        ));
    }

    public function code(): string
    {
        return 'consent_mismatch';
    }
}
