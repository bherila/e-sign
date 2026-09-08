<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\DocumentStorageKey;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Documents\RevisionKind;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The rows and bytes a retention test needs, and the one thing every one of them has to do:
 * make something *old*.
 *
 * Retention is entirely about elapsed time, and nothing in a test suite is old. Every
 * candidate is therefore backdated through the query builder rather than by travelling the
 * clock, because `Carbon::setTestNow()` would also move the cutoffs the sweep computes and
 * the two would cancel out. Writing the timestamp directly is the only way to build a row
 * that genuinely looks ninety days old to a query.
 *
 * All data is synthetic (AGENTS.md).
 */
final class RetentionScenario
{
    /** A signed envelope taken all the way through publication: three artifacts, real bytes. */
    public static function finalized(): FinalizationScenario
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        return $scenario;
    }

    /**
     * Move a row's timestamps into the past, through the query builder.
     *
     * @param  array<string, CarbonImmutable|string|null>  $columns
     */
    public static function backdate(string $table, int $id, array $columns): void
    {
        DB::table($table)->where('id', $id)->update(array_map(
            static fn (mixed $value): mixed => $value instanceof CarbonImmutable
                ? $value->toDateTimeString()
                : $value,
            $columns,
        ));
    }

    public static function daysAgo(int $days): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays($days);
    }

    /**
     * An uploaded document with real bytes on the faked disk, referenced by nothing.
     *
     * Both objects are written, because the sweep has to remove the original and the review
     * revision and a test that only checked one would pass with half the bytes left behind.
     */
    public static function orphanDocument(Workspace $workspace, int $ageInDays, string $bytes = '%PDF-1.7 synthetic orphan'): Document
    {
        $document = Document::factory()->for($workspace)->create();

        $originalDigest = hash('sha256', $bytes);
        $originalPath = DocumentStorageKey::for(
            $workspace->public_id,
            $document->public_id,
            RevisionKind::Original,
            $originalDigest,
        )->value;

        $reviewBytes = $bytes.' (review)';
        $reviewDigest = hash('sha256', $reviewBytes);
        $reviewPath = DocumentStorageKey::for(
            $workspace->public_id,
            $document->public_id,
            RevisionKind::Review,
            $reviewDigest,
        )->value;

        Storage::disk('documents')->put($originalPath, $bytes);
        Storage::disk('documents')->put($reviewPath, $reviewBytes);

        $document->forceFill([
            'original_disk' => 'documents',
            'original_path' => $originalPath,
            'original_sha256' => $originalDigest,
            'original_bytes' => strlen($bytes),
        ])->save();

        foreach ([
            [RevisionKind::Original, $originalPath, $originalDigest, $bytes],
            [RevisionKind::Review, $reviewPath, $reviewDigest, $reviewBytes],
        ] as [$kind, $path, $digest, $content]) {
            DocumentRevision::query()->create([
                'document_id' => $document->getKey(),
                'kind' => $kind,
                'disk' => 'documents',
                'path' => $path,
                'sha256' => $digest,
                'bytes' => strlen($content),
                'page_count' => 1,
                'normalization' => ['applied' => false],
            ]);
        }

        self::backdate('documents', (int) $document->getKey(), [
            'created_at' => self::daysAgo($ageInDays),
            'updated_at' => self::daysAgo($ageInDays),
        ]);

        return $document->refresh();
    }

    /**
     * Every object path the document and its revisions name.
     *
     * @return list<string>
     */
    public static function objectsOf(Document $document): array
    {
        $paths = [$document->original_path];

        foreach (DB::table('document_revisions')->where('document_id', $document->getKey())->pluck('path') as $path) {
            $paths[] = (string) $path;
        }

        return array_values(array_unique($paths));
    }

    /** Make a completed envelope look as though it finished long ago. */
    public static function completedDaysAgo(Envelope $envelope, int $days): void
    {
        self::backdate('envelopes', (int) $envelope->getKey(), [
            'completed_at' => self::daysAgo($days),
        ]);
    }

    /** Make a soft-deleted envelope look as though retention removed it long ago. */
    public static function softDeletedDaysAgo(Envelope $envelope, int $days): void
    {
        self::backdate('envelopes', (int) $envelope->getKey(), [
            'deleted_at' => self::daysAgo($days),
        ]);
    }
}
