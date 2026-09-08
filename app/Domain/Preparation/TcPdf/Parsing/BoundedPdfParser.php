<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use Com\Tecnick\Pdf\Filter\Exception as FilterException;
use Com\Tecnick\Pdf\Filter\Filter;
use Com\Tecnick\Pdf\Parser\Exception as ParserException;
use Com\Tecnick\Pdf\Parser\Parser;

/**
 * tc-lib-pdf-parser with this application's resource budget wired into it.
 *
 * The library is used unmodified — nothing is vendored and nothing is patched. It exposes
 * three protected seams, and each one is the point where a cost is actually incurred:
 *
 *  - `getDecodedStream()` is the single choke point every filter chain passes through, so
 *    it is where decompression is capped and charged. The cap handed to the filter layer
 *    is the *remaining* aggregate budget, not a fixed per-stream number, which means zlib
 *    refuses an over-budget stream instead of inflating it and letting us measure the
 *    corpse. `Com\Tecnick\Pdf\Filter\Filter` is called directly rather than through
 *    `parent::getDecodedStream()` because the parent forces its own per-stream
 *    `MaxOutputSize` over anything passed in.
 *  - `getXrefData()` and `parseXrefIndexSections()` see the object count a document
 *    *declares* before the entries behind it are built, which is what stops a
 *    few-kilobyte cross-reference stream from materialising a million entries.
 *  - `getIndirectObject()` is called once per object as it is read, which is where the
 *    object ceiling is charged and where the wall-clock and memory backstops tick.
 *
 * Known gap, covered by the backstops rather than by this class: the library parses the
 * bodies it extracts from an `/ObjStm` in a nested `new self(...)` instance, which is a
 * plain `Parser` and therefore does not charge this budget. A stream object inside an
 * object stream is invalid per ISO 32000-1 §7.5.7, so nothing legitimate takes that path,
 * but a crafted document can. Its inflation is still bounded per stream by
 * `max_stream_size`, and the aggregate exposure is bounded by the time and memory
 * backstops. Recorded in docs/security/review-2026-09.md under U-1.
 *
 * @phpstan-import-type RawObjectArray from \Com\Tecnick\Pdf\Parser\Process\RawObject
 */
final class BoundedPdfParser extends Parser
{
    /**
     * @param  PreflightBudget  $budget  Shared with the caller, which reads the totals afterwards.
     * @param  bool  $ignoreFilterErrors  Mirrors the `ignore_filter_errors` config, which the
     *                                    parent keeps private. Preflight sets it false: a stream
     *                                    that cannot be decoded is a rejection, not a shrug.
     */
    public function __construct(
        private readonly PreflightBudget $budget,
        private readonly bool $ignoreFilterErrors = false,
    ) {
        parent::__construct([
            'decode_streams' => true,
            'ignore_filter_errors' => $ignoreFilterErrors,
            'max_stream_size' => $budget->limits->maxDecodedStreamBytes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $xref
     * @return array<string, mixed>
     *
     * @throws PreflightBudgetException
     * @throws ParserException
     */
    protected function getXrefData(int $offset = 0, array $xref = []): array
    {
        $this->budget->tick();

        $data = parent::getXrefData($offset, $xref);

        $entries = $data['xref'] ?? null;
        if (is_array($entries)) {
            $this->budget->assertDeclaredObjects(count($entries));
        }

        return $data;
    }

    /**
     * @param  array<int, mixed>|null  $indexObj
     * @return array<int, array{0:int, 1:int}>|null
     *
     * @throws PreflightBudgetException
     * @throws ParserException
     */
    protected function parseXrefIndexSections(?array $indexObj): ?array
    {
        $sections = parent::parseXrefIndexSections($indexObj);

        if ($sections !== null) {
            $declared = 0;
            foreach ($sections as $section) {
                $declared += $section[1];
            }

            $this->budget->assertDeclaredObjects($declared);
        }

        return $sections;
    }

    /**
     * @return array<int, RawObjectArray>
     *
     * @throws PreflightBudgetException
     * @throws ParserException
     */
    protected function getIndirectObject(string $obj_ref, int $offset = 0, bool $decoding = true): array
    {
        $this->budget->tick();
        $this->budget->countObject($obj_ref);

        return parent::getIndirectObject($obj_ref, $offset, $decoding);
    }

    /**
     * @param  array<string>  $filters
     * @param  array<array-key, mixed>  $params
     * @return array{0: string, 1: array<string>}
     *
     * @throws PreflightBudgetException
     * @throws ParserException
     */
    protected function getDecodedStream(array $filters, string $stream, array $params = []): array
    {
        $this->budget->tick();

        if ($filters === []) {
            // Nothing can expand: the payload is already in the file, and the file is
            // bounded by max_bytes. Charged anyway so the aggregate reflects everything
            // preflight retains, not only the compressed part of it.
            $this->budget->chargeDecodedBytes(strlen($stream));

            return [$stream, []];
        }

        $remaining = $this->budget->remainingDecodedBytes();
        if ($remaining <= 0) {
            $this->budget->exhaustDecodedBytes();
        }

        $perStream = $this->budget->limits->maxDecodedStreamBytes;
        $cap = $perStream > 0 ? min($perStream, $remaining) : $remaining;
        // Whichever ceiling is lower is the one the filter layer will report, so remember
        // which one it was before the exception has to be attributed to it.
        $capIsAggregate = $perStream <= 0 || $remaining <= $perStream;

        try {
            $decoded = (new Filter)->decodeAll($filters, $stream, $this->cappedParams($params, count($filters), $cap));
        } catch (FilterException $exception) {
            if (str_contains($exception->getMessage(), 'MaxOutputSize')) {
                // The cap this budget set is what stopped the decode. Which of the two
                // ceilings that cap came from decides which number the message names.
                $this->budget->refuseUndecodableStream($capIsAggregate);
            }

            if (! $this->ignoreFilterErrors) {
                throw new ParserException($exception->getMessage());
            }

            return [$stream, $filters];
        }

        $this->budget->chargeDecodedBytes(strlen($decoded));

        return [$decoded, []];
    }

    /**
     * Force this budget's output cap into the DecodeParms handed to every filter in the
     * chain, so an intermediate result cannot exceed it either.
     *
     * Reimplemented rather than delegated because the parent's equivalent is private and
     * caps at its own fixed per-stream size.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     */
    private function cappedParams(array $params, int $filterCount, int $cap): array
    {
        $forced = ['MaxOutputSize' => $cap];

        // A non-empty list is the per-filter form: every position needs its own cap,
        // including the positions the document left out.
        if ($params !== [] && array_is_list($params)) {
            $capped = [];
            $count = max($filterCount, count($params));
            for ($index = 0; $index < $count; $index++) {
                $capped[$index] = is_array($params[$index] ?? null) ? $forced + $params[$index] : $forced;
            }

            return $capped;
        }

        return $forced + $params;
    }
}
