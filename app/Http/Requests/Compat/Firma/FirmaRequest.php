<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

use App\Domain\Identity\Credentials\CurrentPrincipal;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Integration\Firma\SigningRequestLocator;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The base every facade request extends.
 *
 * It exists to make the same thing impossible to forget that
 * App\Http\Requests\Api\V1\ApiRequest does: **the tenant is the credential's workspace and it
 * comes from nowhere else.** There is no workspace in a facade URL and none accepted in a
 * body, so the lookup is constrained by the credential's workspace before the `{id}` from the
 * URL is used. That order is why another tenant's id is a `404` here and never a `403`
 * (`docs/HANDOFF.md` section 10).
 *
 * `authorize()` returns true and is not where authorization happens. Scope is enforced by the
 * `require-scope` middleware named on the route in `routes/compat-firma.php`, so what an
 * endpoint may do is readable beside the endpoint. A Form Request that also decided scope
 * would give two places to look and, eventually, two different answers.
 */
class FirmaRequest extends FormRequest
{
    private ?Envelope $resolved = null;

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
        return app(CurrentPrincipal::class);
    }

    public function credential(): ServiceCredential
    {
        return $this->principal()->credentialOrFail();
    }

    /** The one tenant this request may see. */
    public function workspace(): Workspace
    {
        return $this->principal()->workspaceOrFail();
    }

    /**
     * The signing request `{id}` names, in this credential's workspace.
     *
     * Memoised, because a controller that reads it and a resource that reads it again are one
     * request about one agreement.
     */
    public function signingRequest(): Envelope
    {
        return $this->resolved ??= app(SigningRequestLocator::class)
            ->find($this->workspace(), $this->routeValue('id'));
    }

    protected function routeValue(string $name): string
    {
        $value = $this->route($name);

        return is_string($value) ? $value : '';
    }
}
