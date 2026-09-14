<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use InvalidArgumentException;

/**
 * Where a document read runs. See docs/adr/0006-process-bounded-document-reads.md.
 */
enum IsolationMode: string
{
    /** A child process where this host can start one; in-process otherwise. */
    case Auto = 'auto';

    /** Always a child process. A host that cannot start one refuses to read documents. */
    case Process = 'process';

    /** In this process, bounded by the cooperative budget alone. */
    case InProcess = 'in_process';

    /**
     * @throws InvalidArgumentException A setting this build does not know, refused where it is read
     *                                  rather than quietly treated as one of the others.
     */
    public static function fromSetting(string $value): self
    {
        return self::tryFrom(trim($value)) ?? throw new InvalidArgumentException(sprintf(
            'esign.documents.isolation.mode is %s; it must be one of auto, process or in_process. '
            .'Set ESIGN_DOCUMENTS_ISOLATION accordingly, or leave it unset for auto.',
            var_export($value, true),
        ));
    }
}
