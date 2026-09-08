<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Outbound;

/**
 * Resolves a hostname to every A and AAAA answer.
 *
 * A seam rather than a direct call to the resolver so the destination policy
 * can be tested against a host that answers with a private address without
 * depending on what the machine running the tests can resolve.
 */
interface HostResolver
{
    /**
     * @return list<string> Every address the host answers with; empty if it does not resolve.
     */
    public function resolve(string $host): array;
}
