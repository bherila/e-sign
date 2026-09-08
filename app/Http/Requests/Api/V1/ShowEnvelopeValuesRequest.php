<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Integration\Native\FieldValueView;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/envelopes/{envelope}/values`.
 *
 * `?include=images` opts into signature and initials bytes. Without it those fields are
 * described — media type, digest, length — and the images stay out of the caller's logs and
 * caches. {@see FieldValueView} explains why that is the default rather than a convenience.
 *
 * An unrecognised `include` value is refused rather than ignored: a client that asked for
 * something and silently got the default cannot tell that it did.
 */
class ShowEnvelopeValuesRequest extends EnvelopeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'include' => ['sometimes', 'string', Rule::in([FieldValueView::INCLUDE_IMAGES])],
        ];
    }

    public function includeImages(): bool
    {
        return $this->query('include') === FieldValueView::INCLUDE_IMAGES;
    }
}
