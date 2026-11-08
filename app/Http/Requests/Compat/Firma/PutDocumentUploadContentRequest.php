<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * `PUT` to a large upload's `upload_url` — step two (issue #182): the file's raw bytes.
 *
 * No API key, like the download links: the URL is the capability, and the route's `signed`
 * middleware has verified it before this runs. It names one upload and nothing else.
 */
class PutDocumentUploadContentRequest extends FormRequest
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

    public function uploadId(): string
    {
        $value = $this->route('upload');

        return is_string($value) ? $value : '';
    }

    /**
     * @return resource
     */
    public function body()
    {
        try {
            $stream = $this->getContent(true);
        } catch (LogicException) {
            // Something earlier read the body as a string; a stream over that string reads the
            // same bytes.
            $stream = fopen('php://temp', 'r+b');

            if ($stream !== false) {
                fwrite($stream, (string) $this->getContent());
                rewind($stream);
            }
        }

        return is_resource($stream) ? $stream : fopen('php://memory', 'rb');
    }
}
