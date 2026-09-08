<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Identity\Credentials\CurrentPrincipal;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The base every `/api/v1` request extends.
 *
 * It exists to make one thing impossible to forget: **the tenant is the credential's
 * workspace, and it comes from nowhere else.** There is no workspace in a native API URL and
 * no workspace accepted in a body. A route asks this object, gets the workspace the
 * presented secret belongs to, and constrains its query with it before it looks at the
 * identifier from the URL — the order docs/HANDOFF.md section 10 requires, and the reason a
 * cross-tenant id is a 404 here rather than a 403.
 *
 * `authorize()` returns true on purpose and is not where authorization happens. Scope is
 * enforced by the `require-scope` middleware named on the route, so the scope an endpoint
 * needs is readable in routes/api.php next to the endpoint instead of buried in a class.
 * A Form Request that also decided scope would give two places to look and, eventually, two
 * different answers.
 */
class ApiRequest extends FormRequest
{
    private ?CurrentPrincipal $principal = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function principal(): CurrentPrincipal
    {
        return $this->principal ??= app(CurrentPrincipal::class);
    }

    /** The credential that authenticated this request. */
    public function credential(): ServiceCredential
    {
        return $this->principal()->credentialOrFail();
    }

    /** The one tenant this request may see. */
    public function workspace(): Workspace
    {
        return $this->principal()->workspaceOrFail();
    }

    protected function routeValue(string $name): string
    {
        $value = $this->route($name);

        return is_string($value) ? $value : '';
    }
}
