<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;

/**
 * Configuration-only check: confirms ESIGN_TSA_URL, when set, parses as an
 * http(s) URL. It never makes a network call from the request path;
 * reachability is checked by the worker at signing time, where a failure is
 * an error rather than a silent downgrade.
 */
final class TsaProbe implements HealthProbe
{
    public function name(): string
    {
        return 'tsa';
    }

    public function check(): ProbeResult
    {
        $url = config('esign.tsa.url');

        if (! is_string($url) || $url === '') {
            return ProbeResult::ok($this->name(), 'No TSA configured; PAdES B-B only.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return ProbeResult::fail($this->name(), 'ESIGN_TSA_URL is not a valid http(s) URL.');
        }

        return ProbeResult::ok($this->name(), 'TSA URL is configured.');
    }
}
