<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health;

/**
 * Minimal IPv4/IPv6 CIDR matcher for the health-endpoint allowlist. No
 * third-party dependency is warranted for a handful of loopback-style
 * ranges.
 */
final class CidrMatcher
{
    /**
     * @param  string[]  $cidrs
     */
    public static function matchesAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::matches($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $subnet = $parts[0];
        $prefix = isset($parts[1]) ? (int) $parts[1] : null;

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        // Mixed families (IPv4 vs IPv6) never match.
        if (strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bits = strlen($ipBinary) * 8;
        $prefix ??= $bits;

        if ($prefix < 0 || $prefix > $bits) {
            return false;
        }

        if ($prefix === 0) {
            return true;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainderBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainderBits)) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }
}
