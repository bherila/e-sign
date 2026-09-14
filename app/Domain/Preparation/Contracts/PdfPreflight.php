<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Contracts;

use App\Domain\Preparation\Preflight\PreflightReport;

/**
 * Inspects an uploaded PDF before anything else touches it.
 *
 * Implementations parse the document for real; a MIME type or file extension check is
 * never sufficient. An implementation must fail closed: anything it cannot classify is
 * a rejection, never a pass. It must never modify the input.
 */
interface PdfPreflight
{
    /**
     * Classify a document. Never throws for a malformed document: an unreadable file
     * comes back as a report carrying an `unparseable` rejection so the caller has one
     * code path and one error surface.
     *
     * @param  string  $pdfBytes  The uploaded bytes, exactly as received.
     *
     * @throws DocumentReadUnavailable When the read fails on the service side. Never a report: a
     *                                 report is recorded against the upload as a statement about
     *                                 the document, and this failure says nothing about it.
     */
    public function inspect(string $pdfBytes): PreflightReport;

    /**
     * The same inspection, under the limits for an artifact this application generated.
     *
     * Every hazard check is unchanged; only upload policy — how large, how many pages, how many
     * objects, how much decoded data a *sender* may submit — is lifted. One definition of
     * "generated", so the ceilings a generated document is admitted under and the ceilings it is
     * read under cannot disagree.
     */
    public function forGenerated(): PdfPreflight;
}
