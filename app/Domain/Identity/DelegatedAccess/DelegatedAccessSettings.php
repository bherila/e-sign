<?php

declare(strict_types=1);

namespace App\Domain\Identity\DelegatedAccess;

use BWH\Auth\OAuth\DelegatedAccess\ActorAssertionVerifier;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use BWH\Auth\OAuth\DelegatedAccess\NonceStore;
use Illuminate\Contracts\Config\Repository;

/**
 * Delegated application access settings (issue #111), read from `esign.delegated_access`.
 *
 * Every value that decides whom to trust is pinned in configuration and never discovered from a
 * request: the provider's issuer, this endpoint's exact URL as the provider calls it, this
 * application's registry key, and the provider's integration public keys. A missing or malformed
 * value is a configuration failure that refuses every request; it never falls back to anything.
 */
final readonly class DelegatedAccessSettings
{
    public function __construct(private Repository $config) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('esign.delegated_access.enabled', false);
    }

    public function application(): string
    {
        return (string) $this->config->get('esign.delegated_access.application', '');
    }

    /**
     * The issuer name identity bindings are stored under: the OAuth provider name sign-in uses.
     *
     * An actor or target subject is matched to a local account through the same binding sign-in
     * resolves, so a person provisioned here and a person who signs in are the same account.
     */
    public function bindingIssuer(): string
    {
        return (string) $this->config->get('bherila-auth.oauth_client.provider', '');
    }

    /**
     * @throws DelegatedAccessException When the configuration cannot build a trustworthy verifier.
     */
    public function verifier(NonceStore $nonces): ActorAssertionVerifier
    {
        $publicKeys = self::parsePublicKeys((string) $this->config->get('esign.delegated_access.public_keys', ''));

        if ($publicKeys === [] || $this->bindingIssuer() === '') {
            throw new DelegatedAccessException('invalid_verifier_configuration');
        }

        return new ActorAssertionVerifier(
            (string) $this->config->get('esign.delegated_access.issuer', ''),
            (string) $this->config->get('esign.delegated_access.endpoint', ''),
            $this->application(),
            $publicKeys,
            $nonces,
        );
    }

    /**
     * Parse `key-id|/path/to/public.pem` pairs, comma-separated, into `key id => PEM`.
     *
     * Any entry that is malformed or names an unreadable file empties the whole map. A partly
     * loaded key set would silently stop accepting the key that failed, and an operator would learn
     * about it from a provider error rather than from this deployment.
     *
     * @return array<string, string>
     */
    public static function parsePublicKeys(string $value): array
    {
        $keys = [];

        foreach (array_filter(array_map('trim', explode(',', $value)), static fn (string $entry): bool => $entry !== '') as $entry) {
            $parts = explode('|', $entry, 2);

            if (count($parts) !== 2 || trim($parts[0]) === '' || isset($keys[trim($parts[0])])) {
                return [];
            }

            $path = trim($parts[1]);
            $pem = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

            if (! is_string($pem) || ! str_contains($pem, 'PUBLIC KEY')) {
                return [];
            }

            $keys[trim($parts[0])] = $pem;
        }

        return $keys;
    }
}
