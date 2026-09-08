<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

/**
 * An upload copied to a temporary path this process owns, with the digest and length
 * measured while it was copied.
 *
 * `sha256` is the authority for the rest of intake: the storage key is derived from it, and
 * the read-back check compares against it.
 */
final readonly class StagedUpload
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $bytes,
    ) {}
}
