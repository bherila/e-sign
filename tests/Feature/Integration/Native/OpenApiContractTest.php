<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Integration\Native\ErrorCode;
use App\Domain\Integration\Native\OpenApiDocument;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The OpenAPI document and the router describe the same API.
 *
 * The document is hand-written. This test is what makes that safe: it compares both
 * directions, so a route added without a description fails here, and a description left
 * behind by a deleted route fails here too. It is the property a generator would have
 * bought, without a generator's habit of emitting schemas nobody reads.
 *
 * The fallback route is excluded. It exists precisely to answer paths the document does not
 * describe, so describing it would be a contradiction.
 */
class OpenApiContractTest extends TestCase
{
    public function test_every_registered_api_v1_route_is_described(): void
    {
        $missing = array_diff($this->registeredOperations(), OpenApiDocument::operations());

        $this->assertSame([], array_values($missing), sprintf(
            "These /api/v1 routes are not in resources/%s:\n  %s",
            OpenApiDocument::PATH,
            implode("\n  ", $missing),
        ));
    }

    public function test_every_documented_operation_exists_in_the_router(): void
    {
        $extra = array_diff(OpenApiDocument::operations(), $this->registeredOperations());

        $this->assertSame([], array_values($extra), sprintf(
            "These operations are described in resources/%s but no route serves them:\n  %s",
            OpenApiDocument::PATH,
            implode("\n  ", $extra),
        ));
    }

    public function test_the_document_is_served_without_authentication(): void
    {
        $response = $this->get('/api/v1/openapi.json');

        $response->assertOk();
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        // Byte-identical to the committed file: a tool reading it over HTTP and a tool
        // reading it from the repository must not see two different contracts.
        $this->assertSame(OpenApiDocument::json(), $response->getContent());
    }

    public function test_the_document_declares_openapi_3_1_and_the_api_v1_server(): void
    {
        $document = OpenApiDocument::toArray();

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame('/api/v1', $document['servers'][0]['url']);
        $this->assertSame([['serviceCredential' => []]], $document['security']);
    }

    /**
     * Every error code the application can emit is in the document's enum.
     *
     * A client switches on `error.code`, so a code that exists in the enum and not in the
     * document is one a generated client cannot represent.
     */
    public function test_every_error_code_is_documented(): void
    {
        $documented = OpenApiDocument::toArray()['components']['schemas']['Error']['properties']['error']['properties']['code']['enum'] ?? [];

        sort($documented);
        $codes = ErrorCode::values();
        sort($codes);

        $this->assertSame($codes, $documented);
    }

    /**
     * Path parameters are named the same on both sides.
     *
     * `{envelope}` in the router and `{id}` in the document would compare equal in neither
     * direction above, so this is really a readability guard: it fails with a clearer message
     * than "one list has an entry the other does not".
     */
    public function test_every_documented_path_parameter_is_declared(): void
    {
        foreach (OpenApiDocument::toArray()['paths'] as $path => $operations) {
            preg_match_all('/\{([^}]+)\}/', (string) $path, $matches);

            foreach ($operations as $method => $operation) {
                $declared = array_column($operation['parameters'] ?? [], 'name');

                foreach ($matches[1] as $parameter) {
                    $this->assertContains($parameter, $declared, sprintf(
                        '%s %s does not declare its path parameter {%s}.',
                        strtoupper((string) $method),
                        $path,
                        $parameter,
                    ));
                }
            }
        }
    }

    /**
     * `METHOD /api/v1/...` for every registered route, minus HEAD (which Laravel adds to
     * every GET) and minus the fallback.
     *
     * @return list<string>
     */
    private function registeredOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if ($route->isFallback || ! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $operations[] = $method.' /'.$route->uri();
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }
}
