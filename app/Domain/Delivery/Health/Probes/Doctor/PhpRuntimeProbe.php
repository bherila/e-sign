<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes\Doctor;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;

/**
 * The PHP CLI binary this process is actually running under: version floor and the extension
 * list `docs/operations/cpanel.md` documents as required. This is the CLI half of the "confirm
 * the actual PHP CLI executable and web runtime both satisfy the supported version/extensions"
 * requirement in docs/HANDOFF.md section 13; see WebPhpVersionProbe for the web half.
 */
final class PhpRuntimeProbe implements HealthProbe
{
    private const MINIMUM_VERSION = '8.4.0';

    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'mbstring',
        'gd',
        'zip',
        'intl',
        'bcmath',
        'openssl',
        'exif',
    ];

    public function name(): string
    {
        return 'php_cli';
    }

    public function check(): ProbeResult
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_VERSION, '<')) {
            return ProbeResult::fail(
                $this->name(),
                sprintf('PHP CLI %s is older than the required %s+.', PHP_VERSION, self::MINIMUM_VERSION),
            );
        }

        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));

        if ($missing !== []) {
            return ProbeResult::fail(
                $this->name(),
                'PHP CLI '.PHP_VERSION.' is missing required extension(s): '.implode(', ', $missing).'.',
            );
        }

        return ProbeResult::ok($this->name(), 'PHP CLI '.PHP_VERSION.' with all required extensions loaded.');
    }
}
