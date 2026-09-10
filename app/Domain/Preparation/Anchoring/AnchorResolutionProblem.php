<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Preparation\Schema\ValidationError;

/**
 * One reason a field's anchor could not be turned into a rectangle.
 *
 * It carries the three facts a sender needs to fix it — which field, what text it was looking
 * for, and what was actually found — in structured form rather than only inside a sentence, so
 * the editor can annotate the offending field and an API client can switch on the code. The
 * same value is rendered two ways: as a {@see ValidationError} with a JSON Pointer for the
 * publish path, and as a send-precondition problem for the send path. Both are 422s carrying
 * every problem at once, because both are moments where the sender can still fix it.
 */
final readonly class AnchorResolutionProblem
{
    public function __construct(
        public int $fieldIndex,
        public string $fieldId,
        public string $recipientId,
        public string $anchorText,
        public ValidationCode $code,
        public string $found,
        public string $message,
        public string $member = 'anchor',
    ) {}

    /**
     * RFC 6901 pointer to the member the sender has to change.
     *
     * Almost always the anchor, because almost every one of these is about the anchor. The
     * exception is a field naming a page the document does not have: what is wrong there is
     * `page`, the anchor is fine, and pointing at the anchor sends an editor to annotate a
     * correct value while the incorrect one goes unmarked. The field-schema validator's own
     * page-range error points at `page`, and two surfaces that disagree about where the same
     * mistake lives are worse than either.
     */
    public function pointer(): string
    {
        return '/fields/'.$this->fieldIndex.'/'.$this->member;
    }

    public function toValidationError(): ValidationError
    {
        return new ValidationError($this->pointer(), $this->code, $this->message);
    }

    /**
     * The shape `SendPreconditionsFailed` publishes, and therefore the shape the native API's
     * `details.problems[]` and the facade's `validation_errors[]` carry.
     *
     * @return array{code: string, message: string, field: string, recipient: string, anchor_text: string, found: string}
     */
    public function toSendProblem(): array
    {
        return [
            'code' => $this->code->value,
            'message' => $this->message,
            'field' => $this->fieldId,
            'recipient' => $this->recipientId,
            'anchor_text' => $this->anchorText,
            'found' => $this->found,
        ];
    }
}
