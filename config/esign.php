<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service seal material
    |--------------------------------------------------------------------------
    |
    | The organizational seal applied to an executed PDF. It is the service's
    | own certificate, never a per-signer certificate: humans provide
    | electronic signatures/assent, and the service then seals the result.
    |
    | The paths point at key material kept outside the repository, image
    | layers, document storage, and logs. Absent, unreadable, expired, or
    | mismatched material is an error at seal time, never a silent skip.
    |
    */

    'seal' => [
        // Versioned identifier for the material below, recorded on every
        // artifact so an old document stays verifiable after a rotation.
        'key_id' => env('ESIGN_SEAL_KEY_ID', ''),

        // PEM X.509 certificate of the service seal.
        'certificate_path' => env('ESIGN_SEAL_CERTIFICATE_PATH', ''),

        // PEM private key matching the certificate above.
        'private_key_path' => env('ESIGN_SEAL_PRIVATE_KEY_PATH', ''),

        // Passphrase for an encrypted private key; empty for an unencrypted one.
        'private_key_passphrase' => env('ESIGN_SEAL_PRIVATE_KEY_PASSPHRASE', ''),

        // Optional PEM bundle holding the chain above the seal certificate,
        // leaf first. Embedded in the CMS so a relying party can build a path.
        'chain_path' => env('ESIGN_SEAL_CHAIN_PATH', ''),

        // CMS digest algorithm. sha256, sha384, and sha512 are accepted; SHA-1
        // is not offered.
        'digest_algorithm' => env('ESIGN_SEAL_DIGEST_ALGORITHM', 'sha256'),
    ],

    /*
    |--------------------------------------------------------------------------
    | RFC 3161 timestamp authority (PAdES B-T)
    |--------------------------------------------------------------------------
    |
    | An unset URL means the deployment can only produce B-B. Requesting B-T
    | without a configured, reachable, and valid TSA is an error: there is no
    | downgrade path from B-T to B-B.
    |
    | The destination is validated before the request leaves the process:
    | HTTPS by default, no credentials in the URL, no redirects followed, and
    | no host that resolves to a private, loopback, link-local, or otherwise
    | reserved address.
    |
    */

    'tsa' => [
        // Example: https://freetsa.org/tsr
        'url' => env('ESIGN_TSA_URL', ''),

        // Transport timeout in seconds for the timestamp request.
        'timeout' => (int) env('ESIGN_TSA_TIMEOUT', 15),

        // Some widely used public TSAs (DigiCert among them) publish an
        // RFC 3161 endpoint over plaintext HTTP only. The timestamp token is
        // signed and nonce-matched, so integrity does not rest on TLS, but the
        // document digest travels in the clear; opting in is deliberate.
        'allow_plaintext_http' => filter_var(
            env('ESIGN_TSA_ALLOW_PLAINTEXT_HTTP', false),
            FILTER_VALIDATE_BOOLEAN
        ),

        // A TSA that still names its certificate with the RFC 2634
        // signing-certificate (v1) attribute is SHA-1 by definition and is
        // refused without this. It relaxes the token check only, never the
        // document signature.
        'allow_sha1_token' => filter_var(
            env('ESIGN_TSA_ALLOW_SHA1_TOKEN', false),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

];
