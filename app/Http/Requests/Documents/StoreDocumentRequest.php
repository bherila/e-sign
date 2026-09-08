<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * POST /workspaces/{workspace}/documents.
 *
 * Sender and above: uploading is the first step of creating an envelope, so it takes the
 * same permission. An auditor, who may read everything in the workspace, may not upload.
 *
 * The MIME allowlist is checked against the file's sniffed type (`mimetypes:`), not against
 * its extension and not against the Content-Type the client declared. That is a first gate
 * only, and the smaller half of the job: AGENTS.md requires the real preflight parser to
 * run afterwards, because a file can sniff as application/pdf and still be encrypted,
 * already signed, or carrying XFA.
 *
 * The size ceiling is `esign.documents.max_bytes`, the same number the preflight parser
 * enforces. Refusing an oversized body here means intake never stages, hashes, or stores
 * bytes it was always going to reject.
 */
class StoreDocumentRequest extends WorkspaceDocumentRequest
{
    protected function permission(): WorkspacePermission
    {
        return WorkspacePermission::CreateEnvelopes;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array<int, string> $mimetypes */
        $mimetypes = config('esign.documents.allowed_mimetypes', ['application/pdf']);

        return [
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', $mimetypes),
                'max:'.self::maxKilobytes(),
            ],
            'title' => ['sometimes', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Attach the PDF to upload.',
            'file.mimetypes' => 'Only PDF files can be uploaded. The file you sent is not a PDF.',
            'file.max' => sprintf(
                'The file is larger than the %s MB upload limit. Split the document or reduce embedded '
                .'image resolution before uploading.',
                rtrim(rtrim(number_format(self::maxKilobytes() / 1024, 1), '0'), '.'),
            ),
        ];
    }

    public function title(): ?string
    {
        $title = $this->input('title');

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /** Laravel's `max` rule counts kilobytes; the configured ceiling is in bytes. */
    private static function maxKilobytes(): int
    {
        return max(1, intdiv((int) config('esign.documents.max_bytes'), 1024));
    }
}
