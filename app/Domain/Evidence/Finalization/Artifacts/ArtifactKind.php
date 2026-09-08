<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Artifacts;

/**
 * The three objects a finalization publishes.
 *
 * They are separate artifacts rather than one bundle because they answer different
 * questions and are handed to different readers: the executed PDF is the agreement, the
 * evidence document is what a machine checks it against, and the completion report is what a
 * person reads. docs/HANDOFF.md section 8 requires all three in an export, and requires the
 * completion report to be labelled separately from an X.509 certificate — it is a statement
 * about what happened, not a credential.
 */
enum ArtifactKind: string
{
    /** The signed document with every field value drawn on it, sealed under the service certificate. */
    case ExecutedPdf = 'executed_pdf';

    /**
     * A one-page human-readable summary of the execution: who signed, when, what was
     * attested, at what assurance level, under which seal key.
     *
     * The same content appears as the last page of the executed PDF. It is published on its
     * own as well so an export can hand it to a reader who should not have to open the
     * agreement to see what happened to it.
     */
    case CompletionReport = 'completion_report';

    /** The canonical, versioned machine evidence document. Every digest and what it covers. */
    case EvidenceJson = 'evidence_json';

    /** The file extension the storage key ends in. */
    public function extension(): string
    {
        return $this === self::EvidenceJson ? 'json' : 'pdf';
    }

    /**
     * The content type a download is served with.
     *
     * Fixed per kind and never taken from the stored object or from a request
     * (docs/HANDOFF.md section 12): a content type a caller can influence is a way to have
     * bytes interpreted as something they are not.
     */
    public function contentType(): string
    {
        return $this === self::EvidenceJson ? 'application/json' : 'application/pdf';
    }

    /** True when the bytes carry a PAdES seal, and so have a validation report. */
    public function isSealed(): bool
    {
        return $this === self::ExecutedPdf;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
