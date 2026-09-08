<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Credentials\CurrentPrincipal;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\UnknownScope;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level scope enforcement for the authenticated service principal.
 * Alias: `require-scope`.
 *
 *     Route::middleware(['service-credential', 'require-scope:envelopes:read'])->get(...);
 *     Route::middleware(['service-credential', 'require-scope:envelopes:read,envelopes:write'])->patch(...);
 *
 * Several scopes mean **all** of them. There is no "any of" form: an endpoint that would
 * accept either of two scopes is two endpoints or one narrower scope, and a middleware
 * string is the wrong place to express a disjunction nobody will notice reading the route.
 *
 * The scope names are the wire values of App\Domain\Identity\Credentials\Scope. A name that
 * is not a known scope is a mistake in the route definition, not a request problem, so it
 * throws rather than quietly denying: a route protected by a typo would fail closed today
 * and be "fixed" by deleting the middleware tomorrow.
 *
 * Missing principal is 401 (the route is misordered or unauthenticated), missing scope is
 * 403 (the caller is who it says it is and still may not do this).
 */
class RequireScope
{
    public function __construct(private readonly CurrentPrincipal $principal) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $required = $this->required($scopes);

        if (! $this->principal->isServiceCredential()) {
            return response()->json([
                'message' => 'No API credential was presented.',
                'error' => 'invalid_credential',
            ], Response::HTTP_UNAUTHORIZED);
        }

        foreach ($required as $scope) {
            if (! $this->principal->hasScope($scope)) {
                return $this->forbid($scope);
            }
        }

        return $next($request);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<Scope>
     */
    private function required(array $scopes): array
    {
        if ($scopes === []) {
            throw new InvalidArgumentException(
                'RequireScope needs at least one scope, for example require-scope:envelopes:read.'
            );
        }

        try {
            return array_map(static fn (string $scope): Scope => Scope::fromValue($scope), $scopes);
        } catch (UnknownScope $exception) {
            throw new InvalidArgumentException(
                'A route requires a scope this application does not define. '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function forbid(Scope $scope): JsonResponse
    {
        return response()->json([
            'message' => "This API credential is not granted '{$scope->value}'.",
            'error' => 'insufficient_scope',
            'required_scope' => $scope->value,
        ], Response::HTTP_FORBIDDEN, [
            'WWW-Authenticate' => sprintf(
                'Bearer realm="esign", error="insufficient_scope", scope="%s"',
                $scope->value,
            ),
        ]);
    }
}
