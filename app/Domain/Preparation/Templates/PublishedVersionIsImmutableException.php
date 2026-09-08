<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Templates;

use RuntimeException;

/**
 * Thrown when something tries to change or delete a published template version.
 *
 * A typed exception rather than a bare RuntimeException because this is a load-bearing
 * invariant with an HTTP answer: docs/HANDOFF.md section 6 requires that later template
 * changes never mutate existing requests, so the adapter turns this into a 409 with an
 * instruction to create the next version instead of a 500.
 *
 * `$templateVersionId` is the public ULID when the row has one; a version being constructed
 * has not been assigned one yet, which is why it is nullable.
 */
final class PublishedVersionIsImmutableException extends RuntimeException
{
    public function __construct(
        public readonly ?string $templateVersionId,
        string $message = 'A published template version is immutable. Create the next version instead of editing this one.',
    ) {
        parent::__construct($message);
    }
}
