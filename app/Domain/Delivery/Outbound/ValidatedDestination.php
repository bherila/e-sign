<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Outbound;

/**
 * A destination that passed the policy, plus everything needed to connect to
 * exactly the addresses that were checked.
 */
final readonly class ValidatedDestination
{
    /**
     * @param  list<string>  $addresses  Every resolved address, all of them checked.
     */
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public array $addresses,
    ) {}

    /**
     * A CURLOPT_RESOLVE entry pinning the host:port to the checked addresses.
     *
     * Pinning closes the window between validation and connection in which a
     * second DNS answer could move the target (rebinding). cURL accepts a
     * comma-separated address list, so the pin covers every answer that was
     * checked rather than only the first.
     */
    public function curlResolveEntry(): string
    {
        return $this->host.':'.$this->port.':'.implode(',', $this->addresses);
    }
}
