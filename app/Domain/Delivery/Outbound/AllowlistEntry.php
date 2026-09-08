<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Outbound;

use App\Domain\Delivery\Health\CidrMatcher;

/**
 * One administrator-configured exception to the destination policy.
 *
 * An entry names either a host (exact, case-insensitive) or a CIDR block, and
 * carries the two things an operator can grant: reaching a non-public address,
 * and speaking plaintext HTTP. There is deliberately no caller-controlled way
 * to produce one of these: the list comes from `config('esign.delivery.
 * destination_allowlist')`, which comes from deployment configuration.
 */
final readonly class AllowlistEntry
{
    public function __construct(
        public string $value,
        public bool $allowPrivate = false,
        public bool $allowPlaintext = false,
    ) {}

    /**
     * Build an entry from the configuration array shape.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry): ?self
    {
        $value = $entry['host'] ?? $entry['cidr'] ?? $entry['value'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return new self(
            value: strtolower(trim($value)),
            allowPrivate: ($entry['allow_private'] ?? false) === true,
            allowPlaintext: ($entry['allow_plaintext'] ?? false) === true,
        );
    }

    public function isCidr(): bool
    {
        return str_contains($this->value, '/');
    }

    /**
     * Does this entry name the host in the URL?
     *
     * A CIDR entry names a host only when the URL carries an IP literal, which
     * is why a plaintext grant expressed as a CIDR does not cover a hostname:
     * the scheme is judged before DNS is consulted.
     */
    public function matchesHost(string $host): bool
    {
        $literal = trim($host, '[]');

        if ($this->isCidr()) {
            return filter_var($literal, FILTER_VALIDATE_IP) !== false
                && CidrMatcher::matches($literal, $this->value);
        }

        return strtolower($literal) === $this->value;
    }

    public function matchesAddress(string $address): bool
    {
        if ($this->isCidr()) {
            return CidrMatcher::matches($address, $this->value);
        }

        return strtolower($address) === $this->value;
    }
}
