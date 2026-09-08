<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials;

/**
 * What a service credential is allowed to do.
 *
 * A credential carries an explicit list of these values and nothing is implied by anything
 * else. In particular `envelopes:write` does not grant `envelopes:read`, and
 * `compat:firma-v1` does not grant any resource scope: it only admits a credential to the
 * compatibility facade under `/functions/v1/signing-request-api`, whose routes still require
 * the same resource scopes as the native API, because both surfaces call the same domain
 * services (docs/ARCHITECTURE.md). Implication rules read as convenience and behave as
 * privilege escalation the first time someone adds a case in the wrong place, so there are
 * none: an integration that reads and writes is granted both scopes.
 *
 * The string values are the wire format. They appear in `service_credentials.scopes`, in
 * `--scope` on the console, in audit payloads, and in route middleware
 * (`RequireScope:envelopes:read`), so renaming a case value is a breaking change for every
 * issued credential.
 */
enum Scope: string
{
    case EnvelopesRead = 'envelopes:read';
    case EnvelopesWrite = 'envelopes:write';
    case TemplatesRead = 'templates:read';
    case TemplatesWrite = 'templates:write';
    case WebhooksManage = 'webhooks:manage';
    case CompatFirmaV1 = 'compat:firma-v1';

    public function label(): string
    {
        return match ($this) {
            self::EnvelopesRead => 'Read envelopes, recipients, fields, and downloads',
            self::EnvelopesWrite => 'Create, send, patch, and cancel envelopes',
            self::TemplatesRead => 'Read templates and template versions',
            self::TemplatesWrite => 'Create and version templates',
            self::WebhooksManage => 'Administer webhook endpoints and inspect deliveries',
            self::CompatFirmaV1 => 'Call the Firma-compatible facade (profile firma-compat-v1)',
        };
    }

    /**
     * Whether a credential holding `$granted` may act under this scope.
     *
     * The receiver is the scope being demanded and the argument is what the credential
     * actually has, so the call site reads "is envelopes:read satisfied by these grants".
     *
     * @param  list<self>  $granted
     */
    public function satisfiedBy(array $granted): bool
    {
        return in_array($this, $granted, true);
    }

    /**
     * Fail closed unless `$granted` includes this scope.
     *
     * The enforcement helper for domain services and middleware: "require this scope of
     * these grants". Callers that want a boolean use `satisfiedBy()` instead; callers that
     * would otherwise have to remember to throw use this one, because a forgotten `if` is
     * an authorization bypass and a forgotten `try` is only a 500.
     *
     * @param  list<self>  $granted
     *
     * @throws MissingScope
     */
    public function requiresScope(array $granted): void
    {
        if (! $this->satisfiedBy($granted)) {
            throw MissingScope::for($this, $granted);
        }
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * Resolve one untrusted scope string.
     *
     * @throws UnknownScope
     */
    public static function fromValue(string $value): self
    {
        return self::tryFrom(self::normalise($value)) ?? throw UnknownScope::for([$value]);
    }

    /**
     * Resolve an untrusted list of scope strings, rejecting the whole list if any member is
     * not a known scope.
     *
     * This is the gate the issuer runs at issue time. It is all-or-nothing on purpose: an
     * operator who mistypes one scope gets an error naming it, never a credential that was
     * quietly issued with less authority than they asked for. Duplicates collapse and the
     * result is ordered by the enum's own case order, so the stored list is canonical and
     * two credentials granted the same scopes compare equal.
     *
     * @param  iterable<mixed>  $values
     * @return list<self>
     *
     * @throws UnknownScope
     */
    public static function fromValues(iterable $values): array
    {
        $unknown = [];
        $resolved = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                $unknown[] = get_debug_type($value);

                continue;
            }

            $normalised = self::normalise($value);

            if ($normalised === '') {
                continue;
            }

            $scope = self::tryFrom($normalised);

            if ($scope === null) {
                $unknown[] = $value;

                continue;
            }

            $resolved[$scope->value] = $scope;
        }

        if ($unknown !== []) {
            throw UnknownScope::for($unknown);
        }

        return array_values(array_filter(
            self::cases(),
            static fn (self $scope): bool => isset($resolved[$scope->value]),
        ));
    }

    /**
     * Accept whitespace and casing differences from a console flag or a stored row. Nothing
     * else is coerced: an unrecognised value stays unrecognised.
     */
    private static function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
