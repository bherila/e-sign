<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\Exceptions\FinalizationException;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Documents\RevisionKind;
use App\Domain\Signing\Fields\CanonicalValue;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Throwable;
use ZipArchive;

/**
 * Builds the evidence export docs/HANDOFF.md section 8 requires: "the original, reviewed
 * revision, executed PDF, machine-readable evidence, human-readable completion report,
 * certificate chain, validation report, and explicit digests".
 *
 * Every one of those is a separate file in the archive, and `manifest.json` lists each with
 * its SHA-256 and a sentence saying what that digest covers. The manifest is the reason the
 * export is useful at all: a zip of seven files with no statement of which digest belongs to
 * which object is a pile of bytes, not evidence.
 *
 * ## Deliberate limits
 *
 * The manifest is **not** signed. docs/HANDOFF.md allows for "a separately signed export
 * manifest" as a possibility, and signing one with the same seal key would add no independent
 * assurance — a party who does not trust the application's copy of the evidence has no reason
 * to trust the application's signature over its own list of it. The executed PDF inside the
 * archive carries the seal that matters, and the manifest says so.
 *
 * The archive is written to a temporary file rather than built in memory, because an export
 * is the one operation whose size is the sum of every artifact plus the original upload, and
 * the caller streams it. Deleting that file is the caller's job, which is why the path is
 * returned rather than a response.
 */
final readonly class EvidenceExporter
{
    public const MANIFEST = 'manifest.json';

    public const MANIFEST_VERSION = 1;

    public function __construct(
        private ArtifactStore $store,
        private DocumentBlobStore $documents,
        private SealIdentity $sealIdentity,
    ) {}

    /**
     * Write the export and return the temporary file's absolute path.
     *
     * @throws FinalizationException When the envelope has no published evidence, or a member
     *                               cannot be read.
     */
    public function bundle(Envelope $envelope): string
    {
        $artifacts = Artifact::query()
            ->where('envelope_id', $envelope->getKey())
            ->whereNotNull('published_at')
            ->get()
            ->keyBy(static fn (Artifact $artifact): string => $artifact->kind->value);

        if ($artifacts->isEmpty()) {
            throw new FinalizationException(
                'That envelope has no published artifacts, so there is nothing to export. An export '
                .'of a partial execution would look like a record of one.',
            );
        }

        $members = [];

        foreach (ArtifactKind::cases() as $kind) {
            $artifact = $artifacts->get($kind->value);

            if (! $artifact instanceof Artifact) {
                continue;
            }

            $members[$this->memberName($kind)] = [
                'bytes' => $this->store->get($artifact->disk, $artifact->path),
                'recorded_sha256' => $artifact->sha256,
                'covers' => $this->coversFor($kind),
            ];
        }

        foreach ($this->documentRevisions($envelope) as $name => $revision) {
            $bytes = $this->documents->disk($revision->disk)->get($revision->path);

            if (! is_string($bytes)) {
                throw new FinalizationException(
                    'A document revision named by this envelope could not be read, so the export would '
                    .'be missing a file it claims to contain.',
                );
            }

            $members[$name] = [
                'bytes' => $bytes,
                'recorded_sha256' => $revision->sha256,
                'covers' => $name === 'original-upload.pdf'
                    ? 'The uploaded document exactly as received, never re-rendered or re-sealed.'
                    : 'The reviewed revision every acceptance is bound to.',
            ];
        }

        $certificate = $this->certificateChain();

        if ($certificate !== null) {
            $members['seal-certificate.pem'] = [
                'bytes' => $certificate,
                'recorded_sha256' => null,
                'covers' => 'The service seal certificate, leaf first, followed by any configured chain '
                    .'above it. This is the certificate the executed PDF was sealed under; it is not a '
                    .'certificate belonging to any signer.',
            ];
        }

        $executed = $artifacts->get(ArtifactKind::ExecutedPdf->value);

        if ($executed instanceof Artifact && $executed->validation_report !== null) {
            $members['validation-report.json'] = [
                'bytes' => CanonicalValue::encode($executed->validation_report),
                'recorded_sha256' => null,
                'covers' => 'What the sealed executed PDF turned out to be when its bytes were read back '
                    .'before publication. An in-process check, not an independent one.',
            ];
        }

        return $this->write($envelope, $members, $artifacts->get(ArtifactKind::ExecutedPdf->value));
    }

    /**
     * @param  array<string, array{bytes: string, recorded_sha256: string|null, covers: string}>  $members
     */
    private function write(Envelope $envelope, array $members, ?Artifact $executed): string
    {
        $path = tempnam(sys_get_temp_dir(), 'esign-evidence-');

        if ($path === false) {
            throw new FinalizationException('A temporary file for the evidence export could not be created.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new FinalizationException('The evidence export archive could not be opened for writing.');
        }

        try {
            $entries = [];

            foreach ($members as $name => $member) {
                $digest = hash('sha256', $member['bytes']);

                if ($member['recorded_sha256'] !== null && ! hash_equals($member['recorded_sha256'], $digest)) {
                    throw new FinalizationException(
                        'A file read for the evidence export does not hash to the digest its record names, '
                        .'so the export is refused rather than shipped with a mismatch in it.',
                    );
                }

                $zip->addFromString($name, $member['bytes']);

                $entries[] = [
                    'file' => $name,
                    'sha256' => $digest,
                    'bytes' => strlen($member['bytes']),
                    'covers' => $member['covers'],
                ];
            }

            $zip->addFromString(self::MANIFEST, CanonicalValue::encode([
                'manifest_version' => self::MANIFEST_VERSION,
                'encoding' => 'esign.export-manifest.v1',
                'envelope' => $envelope->public_id,
                'title' => $envelope->title,
                'exported_at' => CarbonImmutable::now()->utc()->toIso8601ZuluString(),
                'assurance_level_reached' => $executed?->assurance_level_reached?->value,
                'seal_key_id' => $executed?->seal_key_id ?? '',
                'seal_certificate_sha256' => $executed?->seal_certificate_sha256 ?? '',
                'files' => $entries,
                'notes' => [
                    'Every digest above is SHA-256 over the complete bytes of the named file, computed '
                        .'outside that file.',
                    'This manifest is not signed. The seal that matters is the one inside '
                        .$this->memberName(ArtifactKind::ExecutedPdf).'; a signature by the same service '
                        .'over its own inventory would add no independent assurance.',
                    'The completion report is a report, not an X.509 certificate.',
                ],
            ]));
        } catch (Throwable $exception) {
            $zip->close();
            @unlink($path);

            throw $exception instanceof FinalizationException
                ? $exception
                : new FinalizationException(
                    'The evidence export could not be written: '.$exception->getMessage(),
                    previous: $exception,
                );
        }

        $zip->close();

        return $path;
    }

    /**
     * The original upload and the reviewed revision, when the document row still holds them.
     *
     * @return array<string, DocumentRevision>
     */
    private function documentRevisions(Envelope $envelope): array
    {
        $review = DocumentRevision::query()->find($envelope->document_revision_id);

        if ($review === null) {
            return [];
        }

        $found = ['reviewed-revision.pdf' => $review];

        $original = DocumentRevision::query()
            ->where('document_id', $review->document_id)
            ->where('kind', RevisionKind::Original->value)
            ->orderBy('id')
            ->first();

        if ($original !== null && $original->getKey() !== $review->getKey()) {
            $found = ['original-upload.pdf' => $original, ...$found];
        }

        return $found;
    }

    /** The seal certificate followed by any configured chain, or null when unreadable. */
    private function certificateChain(): ?string
    {
        try {
            $chain = $this->sealIdentity->chainPem();

            return rtrim($this->sealIdentity->certificatePem())."\n".ltrim($chain);
        } catch (SealingException) {
            // An export of an old artifact must still be possible after the material it was
            // sealed with has been rotated out of the configuration. The certificate is also
            // embedded in the executed PDF's CMS, which is what actually verifies it.
            return null;
        }
    }

    private function memberName(ArtifactKind $kind): string
    {
        return match ($kind) {
            ArtifactKind::ExecutedPdf => 'executed-agreement.pdf',
            ArtifactKind::CompletionReport => 'completion-report.pdf',
            ArtifactKind::EvidenceJson => 'evidence.json',
        };
    }

    private function coversFor(ArtifactKind $kind): string
    {
        return match ($kind) {
            ArtifactKind::ExecutedPdf => 'The executed agreement: the reviewed revision with every field '
                .'value drawn on it and the completion report appended, sealed under the service '
                .'certificate.',
            ArtifactKind::CompletionReport => 'The human-readable report. The same content is the last '
                .'page of the executed agreement.',
            ArtifactKind::EvidenceJson => 'The machine-readable evidence document: every digest, what it '
                .'covers, the attestation chain, and the distinct acceptance and sealing times.',
        };
    }
}
