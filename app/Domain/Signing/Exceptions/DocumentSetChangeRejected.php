<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

/**
 * A change to a draft's document set that cannot be made (issue #182).
 *
 * One class with a stable `reason`, because every surface translates them the same way and
 * each reason is a distinct answer upstream: a set already at its limit, an order that does not
 * name every document, a replacement with a different number of pages. Nothing is written when
 * one is thrown: the change is computed and checked under the envelope lock, before any row
 * moves.
 */
final class DocumentSetChangeRejected extends SigningException
{
    public const NOT_FOUND = 'document_not_found';

    public const TOO_MANY = 'max_documents_exceeded';

    public const TOO_LARGE = 'document_set_too_large';

    public const INVALID_ORDER = 'invalid_document_order';

    public const MIN_DOCUMENTS = 'min_documents_required';

    public const PAGE_COUNT_MISMATCH = 'page_count_mismatch';

    public const DIMENSION_MISMATCH = 'dimension_mismatch';

    public const FIELDS_ON_DOCUMENT = 'fields_on_document';

    public const INVALID_POSITION = 'invalid_position';

    public const NOT_READY = 'document_not_ready';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function code(): string
    {
        return $this->reason;
    }

    public static function notFound(string $documentId): self
    {
        return new self(self::NOT_FOUND, 'This signing request has no document "'.$documentId.'".', ['document_id' => $documentId]);
    }

    public static function tooMany(int $max): self
    {
        return new self(self::TOO_MANY, 'A document set holds at most '.$max.' documents.', ['max_documents' => $max]);
    }

    public static function tooLarge(int $bytes, int $max): self
    {
        return new self(
            self::TOO_LARGE,
            'The document set would be '.$bytes.' bytes, over the '.$max.'-byte limit.',
            ['bytes' => $bytes, 'max_bytes' => $max],
        );
    }

    public static function invalidOrder(string $why): self
    {
        return new self(self::INVALID_ORDER, 'The new order must name every document of the set exactly once: '.$why);
    }

    public static function minDocuments(): self
    {
        return new self(self::MIN_DOCUMENTS, 'The last remaining document cannot be removed. A signing request needs at least one.');
    }

    public static function invalidPosition(int $position, int $max): self
    {
        return new self(
            self::INVALID_POSITION,
            '`position` must be between 1 and '.$max.'; got '.$position.'.',
            ['position' => $position, 'max_position' => $max],
        );
    }

    public static function pageCountMismatch(int $current, int $replacement): self
    {
        return new self(
            self::PAGE_COUNT_MISMATCH,
            'A replacement must have the same number of pages as the document it replaces: '.$current
            .' pages, and the replacement has '.$replacement.'. Remove the document and add the new one instead.',
            ['page_count' => $current, 'replacement_page_count' => $replacement],
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function dimensionMismatch(string $why, array $details = []): self
    {
        return new self(
            self::DIMENSION_MISMATCH,
            'A replacement must have every page the same size, within 1 pt, as the document it replaces, so '
            .'that every placed field stays where it was put: '.$why,
            $details,
        );
    }

    /**
     * @param  list<string>  $fieldIds
     */
    public static function fieldsOnDocument(string $documentId, array $fieldIds): self
    {
        return new self(
            self::FIELDS_ON_DOCUMENT,
            'Document "'.$documentId.'" still has fields on it ('.implode(', ', $fieldIds).'). Removing it would '
            .'leave them on pages the signing request no longer has, so it is refused; this build cannot move '
            .'or delete a placed field.',
            ['document_id' => $documentId, 'fields' => $fieldIds],
        );
    }

    public static function notReady(string $documentId): self
    {
        return new self(
            self::NOT_READY,
            'Document "'.$documentId.'" has no reviewed revision with a known page count, so it cannot join a set.',
            ['document_id' => $documentId],
        );
    }
}
