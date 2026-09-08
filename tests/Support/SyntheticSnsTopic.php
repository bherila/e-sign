<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A synthetic SNS signing identity: one RSA keypair and one self-signed certificate,
 * generated in the process, used to sign envelopes the way SNS does.
 *
 * No AWS key material is in this repository and none is fetched. The verifier under test
 * does not care who issued the certificate — SNS's own certificates chain to a public CA and
 * `openssl_verify` is given only the public key out of the leaf — so a self-signed
 * certificate served through a faked HTTP client exercises exactly the code path a real one
 * would, including the fetch, the cache, and the destination policy.
 *
 * The string-to-sign is rebuilt here from the AWS specification rather than borrowed from
 * the library under test. That is the point: if `Aws\Sns\MessageValidator` and this class
 * ever disagree about field order or about which fields a `SubscriptionConfirmation`
 * signs, a test fails. Borrowing the library's own `getStringToSign()` would assert only
 * that the library agrees with itself.
 */
final class SyntheticSnsTopic
{
    /**
     * The order AWS specifies. Each present field is emitted as `name\nvalue\n`; an absent
     * field is skipped entirely, which is why a `Notification` without a `Subject` and one
     * with an empty `Subject` are different strings.
     *
     * `SubscribeURL` and `Token` appear only on `SubscriptionConfirmation` and
     * `UnsubscribeConfirmation`; `Subject` appears only on a `Notification` that has one.
     * The single ordered list plus the presence rule covers all three message types, which
     * is the property this list exists to pin.
     */
    private const SIGNABLE_KEYS = [
        'Message',
        'MessageId',
        'Subject',
        'SubscribeURL',
        'Timestamp',
        'Token',
        'TopicArn',
        'Type',
    ];

    private readonly OpenSSLAsymmetricKey $key;

    private readonly string $certificate;

    public function __construct()
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a synthetic SNS signing key.');
        }

        $this->key = $key;

        $subject = [
            'countryName' => 'US',
            'organizationName' => 'Synthetic SNS Fixture',
            'commonName' => 'sns.example.invalid',
        ];

        $csr = openssl_csr_new($subject, $key, ['digest_alg' => 'sha256']);

        if ($csr === false) {
            throw new RuntimeException('Could not generate a synthetic SNS certificate request.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new RuntimeException('Could not sign the synthetic SNS certificate.');
        }

        openssl_x509_export($certificate, $pem);

        $this->certificate = $pem;
    }

    /** The PEM an SNS `SigningCertURL` would serve. */
    public function certificatePem(): string
    {
        return $this->certificate;
    }

    /**
     * Sign an envelope the way SNS does, returning it with `Signature` and
     * `SignatureVersion` filled in.
     *
     * @param  array<string, mixed>  $envelope
     * @param  '1'|'2'  $signatureVersion  1 is SHA-1, 2 is SHA-256. Both are real; this
     *                                     application refuses 1 unless it is turned on.
     * @return array<string, mixed>
     */
    public function sign(array $envelope, string $signatureVersion = '2'): array
    {
        $envelope['SignatureVersion'] = $signatureVersion;

        $algorithm = $signatureVersion === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;

        if (openssl_sign($this->stringToSign($envelope), $signature, $this->key, $algorithm) === false) {
            throw new RuntimeException('Could not sign the synthetic SNS envelope.');
        }

        $envelope['Signature'] = base64_encode($signature);

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function stringToSign(array $envelope): string
    {
        $string = '';

        foreach (self::SIGNABLE_KEYS as $key) {
            if (isset($envelope[$key])) {
                $string .= $key."\n".$envelope[$key]."\n";
            }
        }

        return $string;
    }
}
