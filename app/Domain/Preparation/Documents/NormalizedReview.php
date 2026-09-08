<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

/**
 * The bytes that will become the review revision, and the record of how they got there.
 *
 * `record` is stored verbatim in `document_revisions.normalization` and is also what the
 * sender is shown. It always states a positive fact: either "nothing was changed" or
 * exactly which steps ran and what each of them costs. There is no shape of this object
 * that means "we may or may not have altered the document".
 */
final readonly class NormalizedReview
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(
        public string $bytes,
        public string $sha256,
        public ?int $pageCount,
        public array $record,
    ) {}

    public function wasApplied(): bool
    {
        return $this->record['applied'] === true;
    }
}
