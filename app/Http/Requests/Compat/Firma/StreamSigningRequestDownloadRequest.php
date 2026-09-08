<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

use App\Domain\Integration\Firma\DownloadUrlIssuer;
use App\Domain\Integration\Firma\FirmaException;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The signed download link `GET /signing-requests/{id}/download` hands out.
 *
 * This is the one route on the facade that carries **no API key**, and it deliberately does
 * not extend {@see FirmaRequest}: there is no credential to take a workspace from. Its
 * authority is the signature Laravel's `signed` middleware has already verified, which
 * covers the token and the expiry together, so neither can be edited.
 *
 * Because the token is the authority, this class re-resolves the envelope from the id inside
 * it rather than trusting anything else in the URL, and it looks up nothing by any other
 * identifier. The token names one document of one signing request and cannot reach a second
 * agreement, list anything, or mutate anything ({@see DownloadUrlIssuer}).
 *
 * A signing request that has since been withdrawn keeps serving the document the parties were
 * shown. A link minted while it was live is not retroactively a forgery, and the review
 * revision is exactly what a counterparty asking "what did I sign?" needs — with `is_partial`
 * on the JSON route already saying it is not an executed agreement.
 *
 * A draft is served too, because the create response hands the sender a `document_url` for
 * the document they have just uploaded. The `/download` route is a different question and
 * answers a draft with upstream's own `409 no_document_available`: there, "the signing
 * request's document" means the one under signature, and a draft has none.
 */
class StreamSigningRequestDownloadRequest extends FormRequest
{
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

    /**
     * @return array{envelope: Envelope, kind: string}
     *
     * @throws FirmaException
     */
    public function target(): array
    {
        $token = $this->route('token');
        $decoded = app(DownloadUrlIssuer::class)->decode(is_string($token) ? $token : '');

        $envelope = Envelope::query()
            ->where('public_id', $decoded['envelope'])
            ->with('documentRevision.document')
            ->first();

        if (! $envelope instanceof Envelope) {
            throw FirmaException::notFound('download');
        }

        return ['envelope' => $envelope, 'kind' => $decoded['kind']];
    }
}
