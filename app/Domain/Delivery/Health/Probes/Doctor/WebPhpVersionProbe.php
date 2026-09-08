<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes\Doctor;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;

/**
 * Compares this CLI process's PHP version against `config('esign.cpanel.web_php_version')`.
 *
 * ## What this can and cannot detect
 *
 * A CLI script has no way to ask the web SAPI (PHP-FPM/LSAPI/mod_php under Apache) what version
 * it is running; `php artisan` and the browser-facing PHP handler are two entirely separate
 * processes, possibly two entirely separate `ea-phpNN` installations on a cPanel account. This
 * probe therefore does not measure the web runtime directly — it only compares this CLI
 * process's version against whatever the operator recorded in `ESIGN_CPANEL_WEB_PHP_VERSION`,
 * which is expected to be copied from the `AddHandler application/x-httpd-ea-phpNN` line
 * `htaccess-append.txt` appends to `public/.htaccess` on deploy. If that value is wrong or
 * stale, this probe reports a false "ok". Leaving the setting blank is honest: it warns rather
 * than fabricating a comparison it cannot actually make.
 *
 * The failure mode this exists to catch is real and has happened silently before: `.github/
 * workflows/deploy.yml` invokes `artisan` through a hardcoded `ea-php85` binary while the
 * account's default vhost PHP handler can drift to a different version (see the comment above
 * the "Run artisan config clear/cache on server" step in that workflow), which surfaces only as
 * every web request failing composer's platform check.
 */
final class WebPhpVersionProbe implements HealthProbe
{
    public function name(): string
    {
        return 'web_php_version';
    }

    public function check(): ProbeResult
    {
        $configured = trim((string) config('esign.cpanel.web_php_version'));

        if ($configured === '') {
            return ProbeResult::warn(
                $this->name(),
                'ESIGN_CPANEL_WEB_PHP_VERSION is not set; cannot compare the web runtime against this CLI.',
            );
        }

        $cliMajorMinor = implode('.', array_slice(explode('.', PHP_VERSION), 0, 2));
        $webMajorMinor = implode('.', array_slice(explode('.', $configured), 0, 2));

        if ($cliMajorMinor !== $webMajorMinor) {
            return ProbeResult::fail(
                $this->name(),
                "CLI is PHP {$cliMajorMinor}, but ESIGN_CPANEL_WEB_PHP_VERSION reports the web vhost is {$webMajorMinor}.",
            );
        }

        return ProbeResult::ok($this->name(), "CLI and configured web runtime agree: PHP {$cliMajorMinor}.");
    }
}
