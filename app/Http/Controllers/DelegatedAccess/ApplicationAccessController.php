<?php

declare(strict_types=1);

namespace App\Http\Controllers\DelegatedAccess;

use App\Domain\Identity\DelegatedAccess\ApplicationAccessAdapter;
use App\Domain\Identity\DelegatedAccess\DelegatedAccessSettings;
use App\Http\Controllers\Controller;
use BWH\Auth\OAuth\DelegatedAccess\DatabaseNonceStore;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use BWH\Auth\OAuth\DelegatedAccess\NonceStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * POST /application-access: the identity provider's delegated access requests (issue #111).
 *
 * The order is the contract's. The signed actor assertion is verified first, bound to this exact
 * request body, and its single-use nonce consumed. Only then is the body read, validated as
 * contract version 2, and handed to {@see ApplicationAccessAdapter}. The answer is validated
 * against the contract again before it leaves, so this service cannot send the provider a shape it
 * would refuse. There is no fallback to any other kind of authentication on this route.
 */
class ApplicationAccessController extends Controller
{
    public function __invoke(Request $request, DelegatedAccessSettings $settings, ApplicationAccessAdapter $adapter): JsonResponse
    {
        if (! $settings->enabled()) {
            return self::error('not_found', 404);
        }

        // A declared oversize body is refused before it is read. The web server bounds this route's
        // body to the same ceiling (.docker/nginx/nginx.conf), because the global middleware reads a
        // JSON body before any controller runs; this check covers servers without that rule.
        $declared = $request->headers->get('Content-Length');
        if ($declared !== null && (! ctype_digit($declared) || (int) $declared > DelegatedContract::MAX_REQUEST_BYTES)) {
            return self::error('invalid_request', 422);
        }

        $body = $request->getContent();
        $authorization = (string) $request->header('Authorization', '');

        if (strlen($body) > DelegatedContract::MAX_REQUEST_BYTES) {
            return self::error('invalid_request', 422);
        }

        if (! str_starts_with($authorization, 'Bearer ') || strlen($authorization) <= 7) {
            return self::error('invalid_actor_assertion', 401);
        }

        $contract = new DelegatedContract;

        try {
            $actorSubject = $settings->verifier(self::nonces())->verify(substr($authorization, 7), $request->method(), $body);

            try {
                $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new DelegatedAccessException('invalid_request', 422);
            }

            if (! is_array($input) || ($input['contract_version'] ?? null) !== DelegatedContract::VERSION_2
                || ($input['application'] ?? null) !== $settings->application()) {
                throw new DelegatedAccessException('invalid_request', 422);
            }

            unset($input['contract_version'], $input['application']);
            $payload = $contract->request($settings->application(), $input, DelegatedContract::VERSION_2);

            $response = $adapter->handle($actorSubject, $payload);
        } catch (DelegatedAccessException $failure) {
            return self::error($failure->outcome, $failure->status);
        }

        try {
            $contract->response($response, $settings->application(), (string) $payload['operation'], $payload['subject'] ?? null, DelegatedContract::VERSION_2);
        } catch (DelegatedAccessException $invalid) {
            report($invalid);

            return self::error('internal_error', 500);
        }

        return response()->json($response)->header('Cache-Control', 'no-store');
    }

    /**
     * The durable replay store. Tests bind an in-memory one: the database store refuses in-memory
     * SQLite and open transactions, which is how the test suite runs, and it is right to.
     */
    private static function nonces(): NonceStore
    {
        return app()->bound(NonceStore::class) ? app(NonceStore::class) : new DatabaseNonceStore(DB::connection());
    }

    private static function error(string $outcome, int $status): JsonResponse
    {
        return response()->json(['error' => $outcome], $status)->header('Cache-Control', 'no-store');
    }
}
