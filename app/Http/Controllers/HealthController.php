<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Delivery\Health\CidrMatcher;
use App\Domain\Delivery\Health\ReadinessChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /health/ready. Unlike the framework's own `/up`, this probes the
 * database, queue, scheduler, storage, mail configuration, webhook backlog,
 * signing material, and TSA configuration. The detailed body — which never
 * contains a secret, hostname, DSN, path, or document name — is only
 * returned to a caller with a valid operator bearer token or an allowlisted
 * source IP; everyone else gets the HTTP status code and nothing else.
 */
class HealthController extends Controller
{
    public function ready(Request $request, ReadinessChecker $checker): JsonResponse
    {
        $report = $checker->run();

        if (! $this->isAuthorized($request)) {
            return response()->json(['status' => $report->overallStatus()], $report->httpStatus());
        }

        return response()->json($report->toArray(), $report->httpStatus());
    }

    private function isAuthorized(Request $request): bool
    {
        return $this->hasValidToken($request) || $this->isFromAllowedSource($request);
    }

    private function hasValidToken(Request $request): bool
    {
        $configured = (string) config('esign.health_token');

        if ($configured === '') {
            return false;
        }

        $provided = (string) $request->bearerToken();

        if ($provided === '') {
            return false;
        }

        return hash_equals($configured, $provided);
    }

    private function isFromAllowedSource(Request $request): bool
    {
        $ip = $request->ip();

        if ($ip === null) {
            return false;
        }

        /** @var string[] $cidrs */
        $cidrs = (array) config('esign.health_allow_cidrs', []);

        return CidrMatcher::matchesAny($ip, $cidrs);
    }
}
