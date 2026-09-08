<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Credentials\CredentialSecret;
use App\Domain\Identity\Credentials\CurrentPrincipal;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticate an API caller from its service credential. Alias: `service-credential`.
 *
 * Two header syntaxes, both accepted:
 *
 *     Authorization: esk_k3n9x2ab7q1z_<secret>            raw key
 *     Authorization: Bearer esk_k3n9x2ab7q1z_<secret>     bearer
 *
 * The raw form is not sloppiness. The compatibility facade this application replaces sends
 * its API key in `Authorization` with no scheme, and docs/HANDOFF.md section 10 makes
 * accepting it a requirement: "The authorization header must accept the consumer's raw API
 * key, not require Bearer-only syntax." Rejecting it would mean either patching every
 * consumer or shipping a facade that is not compatible. Bearer is accepted for native
 * callers and standard tooling. There is no ambiguity between the two: a raw key always
 * starts with `esk_` and a bearer token never does.
 *
 * What this middleware guarantees to everything downstream:
 *
 *  1. The credential exists, its secret matched under `hash_equals`, it is not revoked, it
 *     has not expired, and its workspace is live. Anything else is 401 — fail closed, no
 *     partial principal, no "authenticated but unknown workspace" state.
 *  2. The credential and its workspace are on `$request->attributes` and bound into
 *     CurrentPrincipal, so a route can constrain a query by workspace *before* it looks a
 *     resource up rather than loading by id and comparing afterwards.
 *  3. The secret is never logged, never put in an exception message, and never written
 *     anywhere. Failures log the public prefix and a reason, which is all an operator needs.
 *     Anything unexpected thrown while a plaintext secret is in scope has its message
 *     scrubbed before it can reach the log (`withoutSecret()`). Deployments should also keep
 *     PHP's default `zend.exception_ignore_args=On` so a stack trace cannot carry the
 *     argument either.
 *
 * Scope enforcement is a separate concern and lives in RequireScope, so that a route names
 * the scope it needs next to itself and nothing is granted by being authenticated alone.
 */
class AuthenticateServiceCredential
{
    /** The verified ServiceCredential. */
    public const CREDENTIAL_ATTRIBUTE = 'service_credential';

    /** The credential's Workspace, already loaded. */
    public const WORKSPACE_ATTRIBUTE = 'service_credential_workspace';

    /** Its id, for the common case of constraining a query. */
    public const WORKSPACE_ID_ATTRIBUTE = 'service_credential_workspace_id';

    /**
     * `last_used_at` is operational telemetry ("is this key still in use, can we revoke
     * it?"), not evidence, so it is written at most once a minute per credential. Writing it
     * on every call would turn a read-only API request into a write, put a row lock in the
     * path of every concurrent caller sharing a key, and buy nothing: nobody makes a
     * revocation decision on second-level precision.
     */
    public const LAST_USED_THROTTLE_SECONDS = 60;

    public function __construct(private readonly CurrentPrincipal $principal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        $presented = $this->presentedSecret($header);

        if ($presented === null) {
            return $this->deny(
                $request,
                'No API credential was presented.',
                null,
                'missing_authorization_header',
                log: false,
            );
        }

        try {
            $prefix = CredentialSecret::prefixFrom($presented);

            if ($prefix === null) {
                return $this->deny($request, 'Invalid API credential.', null, 'malformed_secret');
            }

            $credential = ServiceCredential::query()
                ->forPrefix($prefix)
                ->with('workspace')
                ->first();

            // Unknown prefix and wrong secret get the same answer. A caller that has not
            // proved it holds a secret learns nothing about which prefixes exist.
            if (! $credential instanceof ServiceCredential || ! $credential->matches($presented)) {
                return $this->deny(
                    $request,
                    'Invalid API credential.',
                    $prefix,
                    $credential === null ? 'unknown_prefix' : 'secret_mismatch',
                );
            }

            // Past this point the caller has proved it holds the secret, so a specific
            // reason is safe to return and saves an integrator an afternoon.
            if ($credential->isRevoked()) {
                return $this->deny($request, 'This API credential has been revoked.', $prefix, 'revoked');
            }

            if ($credential->isExpired()) {
                return $this->deny(
                    $request,
                    'This API credential expired at '.($credential->expires_at?->toIso8601String() ?? 'an earlier time').'.',
                    $prefix,
                    'expired',
                );
            }

            $workspace = $credential->workspace;

            if (! $workspace instanceof Workspace) {
                // The workspace was soft-deleted (or hard-deleted, which RESTRICT forbids
                // while credentials exist). Fail closed rather than authenticate a principal
                // with no tenancy boundary.
                return $this->deny($request, 'Invalid API credential.', $prefix, 'workspace_unavailable');
            }

            $this->bind($request, $credential, $workspace);
            $this->recordUse($credential);
        } catch (Throwable $exception) {
            throw $this->withoutSecret($exception, $presented);
        }

        return $next($request);
    }

    /**
     * Pull the secret out of either header syntax.
     *
     * `Bearer` is matched case-insensitively because HTTP auth schemes are, and any other
     * scheme is left alone: a `Basic` header is not a mangled key, and treating it as one
     * would log a base64 password as a credential prefix.
     */
    private function presentedSecret(string $header): ?string
    {
        $header = trim($header);

        if ($header === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return $header;
    }

    private function bind(Request $request, ServiceCredential $credential, Workspace $workspace): void
    {
        $request->attributes->set(self::CREDENTIAL_ATTRIBUTE, $credential);
        $request->attributes->set(self::WORKSPACE_ATTRIBUTE, $workspace);
        $request->attributes->set(self::WORKSPACE_ID_ATTRIBUTE, $workspace->getKey());

        $this->principal->bind($credential);
    }

    /**
     * Throttled `last_used_at` write. Timestamps are switched off for it so that
     * `updated_at` keeps meaning "the credential itself changed" — an operator reading the
     * table can still see when a key was last rotated or relabelled instead of seeing every
     * row touched a minute ago.
     */
    private function recordUse(ServiceCredential $credential): void
    {
        $now = Carbon::now();

        if ($credential->last_used_at !== null
            && $credential->last_used_at->greaterThan($now->clone()->subSeconds(self::LAST_USED_THROTTLE_SECONDS))) {
            return;
        }

        $credential->last_used_at = CarbonImmutable::instance($now);

        $timestamps = $credential->timestamps;
        $credential->timestamps = false;

        try {
            $credential->save();
        } finally {
            $credential->timestamps = $timestamps;
        }
    }

    private function deny(
        Request $request,
        string $message,
        ?string $prefix,
        string $reason,
        bool $log = true,
    ): JsonResponse {
        if ($log) {
            // Prefix and reason only. The presented secret is not in this array and must
            // never be added to it.
            Log::warning('Service credential authentication failed.', [
                'credential_prefix' => $prefix,
                'reason' => $reason,
                'ip' => $request->ip(),
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
            ]);
        }

        return response()->json([
            'message' => $message,
            'error' => 'invalid_credential',
        ], Response::HTTP_UNAUTHORIZED, [
            // Bearer is named because it is the syntax a standards-aware client should use.
            // The raw-key syntax stays accepted for the compatibility facade regardless.
            'WWW-Authenticate' => 'Bearer realm="esign", error="invalid_token"',
        ]);
    }

    /**
     * Replace an exception whose message quotes the presented secret.
     *
     * The original is deliberately not attached as `previous`: keeping it would keep the
     * secret in the chain that gets reported. The class name survives, which is what a
     * debugging operator actually needs from a redacted error.
     */
    private function withoutSecret(Throwable $exception, string $presented): Throwable
    {
        if (! str_contains($exception->getMessage(), $presented)) {
            return $exception;
        }

        return new RuntimeException(
            $exception::class.': message withheld because it contained a presented API credential.',
            $exception->getCode(),
        );
    }
}
