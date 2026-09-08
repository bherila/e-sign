<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Signing\Envelopes\VerificationMethod;
use App\Domain\Signing\Sessions\KeyedDigest;
use App\Domain\Signing\Sessions\OtpPurpose;
use App\Domain\Signing\Sessions\SigningToken;
use App\Domain\Signing\Sessions\SigningVerification;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The primitives underneath guest access: the credential, the keyed digest, and the two
 * string vocabularies the columns hold.
 *
 * The vocabularies are pinned for the same reason `SigningVocabularyTest` pins the state
 * machine's: they back plain `varchar` columns so the DDL reads identically on SQLite, MySQL,
 * and MariaDB, which means the database will accept any string at all and a renamed case is a
 * silent data migration nobody wrote.
 */
class GuestCredentialsTest extends TestCase
{
    public function test_a_token_is_256_bits_of_url_safe_randomness(): void
    {
        $token = SigningToken::generate();

        $this->assertSame(32, SigningToken::ENTROPY_BYTES);
        $this->assertSame(SigningToken::ENCODED_LENGTH, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        $this->assertTrue(SigningToken::looksWellFormed($token));
    }

    public function test_tokens_do_not_repeat(): void
    {
        $tokens = [];

        for ($i = 0; $i < 500; $i++) {
            $tokens[] = SigningToken::generate();
        }

        $this->assertCount(500, array_unique($tokens));
    }

    public function test_the_verifier_is_a_plain_sha256_so_lookup_is_one_indexed_read(): void
    {
        $token = SigningToken::generate();

        $this->assertSame(hash('sha256', $token), SigningToken::hash($token));
        $this->assertTrue(SigningToken::matches($token, SigningToken::hash($token)));
        $this->assertFalse(SigningToken::matches($token.'x', SigningToken::hash($token)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'too short' => [str_repeat('a', 42)],
            'too long' => [str_repeat('a', 44)],
            'a slash' => [str_repeat('a', 42).'/'],
            'a plus' => [str_repeat('a', 42).'+'],
            'padding' => [str_repeat('a', 42).'='],
            'a path traversal' => ['../'.str_repeat('a', 40)],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function test_a_malformed_token_is_recognised_as_one(string $candidate): void
    {
        $this->assertFalse(SigningToken::looksWellFormed($candidate));
    }

    public function test_a_keyed_digest_is_not_reversible_by_enumeration(): void
    {
        $digest = $this->digest();

        // The point of keying: an unkeyed SHA-256 of a six-digit code is a twenty-bit space
        // and is reversed by a loop. This asserts the key actually participates.
        $this->assertNotSame(hash('sha256', '123456'), $digest->of('signing.otp.code', '123456'));
        $this->assertTrue($digest->matches('signing.otp.code', '123456', $digest->of('signing.otp.code', '123456')));
    }

    public function test_namespaces_are_separated_so_two_callers_cannot_collide(): void
    {
        $digest = $this->digest();

        $this->assertNotSame(
            $digest->of('signing.ip', '198.51.100.7'),
            $digest->of('signing.user_agent', '198.51.100.7'),
        );
    }

    public function test_a_different_application_key_produces_different_digests(): void
    {
        $this->assertNotSame(
            $this->digest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->of('signing.ip', '198.51.100.7'),
            $this->digest('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')->of('signing.ip', '198.51.100.7'),
        );
    }

    public function test_the_access_vocabulary_is_the_one_the_column_holds(): void
    {
        $this->assertSame(['link', 'link+otp'], SigningVerification::values());
        $this->assertSame(['session_start', 'legacy_resolve'], OtpPurpose::values());
    }

    public function test_each_access_method_maps_to_exactly_one_evidence_class(): void
    {
        // The two vocabularies meet here and nowhere else, so a new access path has to state
        // which evidence class it belongs to rather than a controller writing a
        // stronger-sounding string onto an attestation.
        $this->assertSame(VerificationMethod::EmailLink, SigningVerification::Link->attested());
        $this->assertSame(VerificationMethod::EmailOtp, SigningVerification::LinkOtp->attested());
    }

    private function digest(string $key = '0123456789abcdef0123456789abcdef'): KeyedDigest
    {
        return new KeyedDigest(new Encrypter($key, 'aes-256-cbc'));
    }
}
