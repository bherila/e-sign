<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Outbound;

/**
 * The administrator-controlled exceptions to the outbound destination policy.
 *
 * This is the only way an outbound request can reach a non-public address or
 * speak plaintext HTTP. It is deployment configuration, never a per-request or
 * per-endpoint flag, so a workspace administrator who can create a webhook
 * endpoint cannot thereby aim it at the instance's own metadata service.
 */
final readonly class DestinationAllowlist
{
    /**
     * @param  list<AllowlistEntry>  $entries
     */
    public function __construct(private array $entries = []) {}

    /**
     * Parse the compact environment form into the configuration array shape.
     *
     * `ESIGN_DELIVERY_ALLOWLIST="consumer.internal.example|private|plaintext,10.8.0.0/24|private"`
     * — comma-separated entries, each a host or CIDR followed by any of the
     * `private` and `plaintext` flags. Kept as plain arrays so `config:cache`
     * can export the result.
     *
     * @return list<array{value: string, allow_private: bool, allow_plaintext: bool}>
     */
    public static function parseEnvironment(?string $raw): array
    {
        $entries = [];

        foreach (explode(',', (string) $raw) as $candidate) {
            $fields = array_values(array_filter(array_map('trim', explode('|', $candidate)), fn (string $f): bool => $f !== ''));

            if ($fields === []) {
                continue;
            }

            $flags = array_map('strtolower', array_slice($fields, 1));

            $entries[] = [
                'value' => $fields[0],
                'allow_private' => in_array('private', $flags, true),
                'allow_plaintext' => in_array('plaintext', $flags, true),
            ];
        }

        return $entries;
    }

    /**
     * @param  iterable<mixed>  $config  The `esign.delivery.destination_allowlist` value.
     */
    public static function fromConfig(iterable $config): self
    {
        $entries = [];

        foreach ($config as $entry) {
            if (is_string($entry)) {
                $entry = ['value' => $entry];
            }

            if (! is_array($entry)) {
                continue;
            }

            $parsed = AllowlistEntry::fromArray($entry);
            if ($parsed instanceof AllowlistEntry) {
                $entries[] = $parsed;
            }
        }

        return new self($entries);
    }

    /**
     * May this host be reached over plaintext HTTP?
     *
     * Judged on the host as written, because the scheme is refused before DNS
     * is consulted; see AllowlistEntry::matchesHost().
     */
    public function permitsPlaintextFor(string $host): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->allowPlaintext && $entry->matchesHost($host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * May this host be reached at this non-public address?
     *
     * Either the host itself is named with `allow_private`, or the address
     * falls inside a CIDR entry that carries it.
     */
    public function permitsPrivateAddress(string $host, string $address): bool
    {
        foreach ($this->entries as $entry) {
            if (! $entry->allowPrivate) {
                continue;
            }

            if ($entry->matchesHost($host) || $entry->matchesAddress($address)) {
                return true;
            }
        }

        return false;
    }
}
