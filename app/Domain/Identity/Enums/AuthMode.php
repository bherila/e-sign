<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

use BWH\Auth\OAuth\OAuthClient;
use InvalidArgumentException;

/**
 * Which sign-in surface this deployment exposes.
 *
 * Exactly one is live at a time and the other's routes are not registered at all, so a
 * standalone install has no callback endpoint to probe and an SSO install has no password
 * form to guess at. That is the point of deciding it here rather than branching inside a
 * controller: an unreachable code path cannot be reached by a request either.
 *
 * `auto` asks the package whether a client has actually been issued for this application.
 * `OAuthClient::isConfigured()` is the only method there that answers without aborting —
 * every other one 503s on a missing setting, which is right for a half-configured deploy
 * and wrong for an installation that is meant to run without a provider at all.
 */
enum AuthMode: string
{
    case Sso = 'sso';
    case Standalone = 'local';

    /**
     * The mode this deployment is running in.
     */
    public static function current(): self
    {
        return self::fromSetting(config('esign.auth_mode', 'auto'));
    }

    /**
     * @param  mixed  $setting  The raw `esign.auth_mode` value.
     */
    public static function fromSetting(mixed $setting): self
    {
        $value = is_string($setting) ? strtolower(trim($setting)) : '';

        return match ($value) {
            'local', 'standalone' => self::Standalone,
            'sso' => self::Sso,
            '', 'auto' => OAuthClient::isConfigured() ? self::Sso : self::Standalone,
            // Fail loudly. Quietly treating a typo as `auto` could turn an SSO deployment
            // into one that serves a password form, which is the opposite of what the
            // operator asked for.
            default => throw new InvalidArgumentException(
                "ESIGN_AUTH_MODE='{$value}' is not a valid authentication mode. Use auto, sso, or local.",
            ),
        };
    }

    public function isSso(): bool
    {
        return $this === self::Sso;
    }

    public function isStandalone(): bool
    {
        return $this === self::Standalone;
    }
}
