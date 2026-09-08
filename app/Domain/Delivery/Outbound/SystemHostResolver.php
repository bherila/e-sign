<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Outbound;

/**
 * The platform resolver: every A record plus every AAAA record.
 *
 * Both families are collected because the policy judges every answer, not the
 * one a connection would happen to pick first.
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = gethostbynamel($host);
        $addresses = $addresses === false ? [] : $addresses;

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ipv6 = $record['ipv6'] ?? null;
                if (is_string($ipv6) && $ipv6 !== '') {
                    $addresses[] = $ipv6;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
