<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use App\Domain\Signing\Sessions\Models\SigningSession;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * A session that has just been created, and the cookie that carries it.
 *
 * The plaintext session token exists here and nowhere else on the server: it goes onto the
 * response as a cookie and the row keeps only its verifier. Returning the cookie already
 * built — rather than the token — means a controller cannot accidentally set it with the
 * wrong path, the wrong `SameSite`, or without `Secure`; every one of those attributes is
 * decided once, in {@see SigningCookie}.
 */
final readonly class StartedSigningSession
{
    public function __construct(
        public SigningSession $session,
        public Cookie $cookie,
    ) {}
}
