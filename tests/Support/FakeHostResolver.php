<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Delivery\Outbound\HostResolver;

/**
 * A resolver with a fixed answer table.
 *
 * The SSRF cases that matter — a hostname that answers with a private address,
 * a hostname that answers with both a public and a private address — cannot be
 * tested against the real resolver without controlling DNS, and a test that
 * depends on what the machine running it can resolve is a test that fails on
 * someone else's laptop.
 */
final class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $answers
     */
    public function __construct(private array $answers = []) {}

    public function resolve(string $host): array
    {
        return $this->answers[strtolower($host)] ?? [];
    }
}
