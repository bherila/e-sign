<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Signing\Sessions\IssuedInvitation;
use App\Domain\Signing\Sessions\SigningCookie;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Driving the guest surface from a feature test the way a browser drives it.
 *
 * The cookie is the whole reason this exists. Laravel encrypts it on the way out, so a test
 * cannot invent one and cannot read the plaintext: the only way to hold a signing session is
 * to complete the same POST a person completes and keep the opaque value that came back. That
 * constraint is deliberate — a helper that minted a session row and set a cookie directly
 * would let every test below it pass without the credential exchange ever being exercised.
 *
 * The encrypted value is carried forward verbatim, so `EncryptCookies` decrypts it on the way
 * back in exactly as it would for a real browser.
 */
trait InteractsWithSigningSession
{
    /**
     * Complete the landing POST and return the opaque cookie value.
     *
     * @param  array<string, mixed>  $data  Form fields, e.g. an OTP code or a return URL.
     */
    protected function startSigningSession(
        GuestSigningScenario $scenario,
        ?IssuedInvitation $issued = null,
        array $data = [],
    ): string {
        $issued ??= $scenario->invite();
        $token = GuestSigningScenario::tokenOf($issued);

        $response = $this->post(GuestSigningScenario::startUrl($scenario->envelope, $token), $data);

        $response->assertRedirect(GuestSigningScenario::sessionUrl($scenario->envelope));

        return $this->signingCookieValue($response);
    }

    /** The encrypted cookie value from a response that issued one. */
    protected function signingCookieValue(TestResponse $response): string
    {
        $cookie = $response->getCookie(SigningCookie::NAME, false);

        Assert::assertNotNull($cookie, 'The response did not issue an '.SigningCookie::NAME.' cookie.');

        return (string) $cookie->getValue();
    }

    /**
     * Make the next request carry the signing cookie, exactly as a browser would.
     *
     * Two pieces of Laravel test-client behaviour have to be worked around, and both are
     * reasonable defaults that happen to be wrong for a cookie-authorized guest surface:
     *
     * 1. `withCookies()` *encrypts* what it is given, assuming a test supplies plaintext. The
     *    value here is already the opaque string `EncryptCookies` produced — the one the
     *    browser holds — so encrypting it again yields a cookie the application cannot read.
     *    `withUnencryptedCookies()` means "send this verbatim", which is what is wanted.
     * 2. `postJson()` sends *no* cookies at all unless `withCredentials()` was called
     *    (`MakesHttpRequests::prepareCookiesForJsonRequest`), on the assumption that a JSON
     *    caller is a token client. The values endpoint is a same-origin `fetch` from a
     *    cookie-authorized page and sets `credentials: "same-origin"`, so the test client is
     *    told the same thing the browser is.
     */
    protected function asSigner(string $cookie): static
    {
        return $this
            ->withCredentials()
            ->withUnencryptedCookies([SigningCookie::NAME => $cookie]);
    }
}
