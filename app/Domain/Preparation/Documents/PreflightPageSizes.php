<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Schema\PageSizes;
use InvalidArgumentException;

/**
 * Displayed page sizes read back out of a document's stored preflight report.
 *
 * A field document carries no geometry, so every check that needs a page's size — does this
 * rectangle fit, does this page exist, did this anchor resolve onto the page — needs the numbers
 * from somewhere. Preflight already measured them, in the native space and after `/Rotate`, and
 * wrote them next to the document; re-parsing the PDF to learn the same thing would be a second
 * measurement that can disagree with the first.
 *
 * Returns null rather than throwing when the report cannot be read: a document that never
 * parsed, or a row made by a factory in a test, must not make a draft impossible. What that
 * costs is that the size-dependent checks are *skipped*, and every message that could have run
 * one says so — an omitted check is never reported as a passed one
 * (docs/preparation/field-schema.md).
 */
final readonly class PreflightPageSizes
{
    public static function of(?Document $document): ?PageSizes
    {
        $report = $document?->preflight_report;
        $pages = is_array($report) ? ($report['pages'] ?? null) : null;

        if (! is_array($pages) || $pages === []) {
            return null;
        }

        $sizes = [];

        foreach ($pages as $page) {
            if (! is_array($page)
                || ! isset($page['page'], $page['native_width'], $page['native_height'])
                || ! is_numeric($page['page'])
                || ! is_numeric($page['native_width'])
                || ! is_numeric($page['native_height'])
            ) {
                return null;
            }

            $sizes[(int) $page['page']] = [
                'width' => (float) $page['native_width'],
                'height' => (float) $page['native_height'],
            ];
        }

        try {
            return PageSizes::fromMap($sizes);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
