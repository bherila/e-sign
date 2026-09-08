<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStorageKey;
use App\Domain\Evidence\Sealing\AssuranceLevel;

/**
 * One artifact's bytes and everything the row about it will say, before the row exists.
 *
 * This is the hand-off between step 2 of the staged publication and step 3: step 2 produces
 * these and proves the bytes are readable, step 3 turns them into `artifacts` rows inside the
 * transaction that completes the envelope. It is also what a crashed run leaves behind on
 * `finalization_runs.outputs`, which is why {@see toArray()} and {@see fromRunOutput()} exist
 * — a retry rebuilds exactly this from the row rather than re-deriving it.
 *
 * `bytes` is empty on the reuse path. The bytes are already in storage and were re-verified
 * by digest there; carrying a copy in memory only to throw it away would double the memory
 * cost of the recovery path for nothing.
 */
final readonly class PreparedArtifact
{
    /**
     * @param  array<string, mixed>|null  $validationReport
     */
    public function __construct(
        public ArtifactKind $kind,
        public string $bytes,
        public string $sha256,
        public int $byteCount,
        public string $disk,
        public ArtifactStorageKey $key,
        public string $sealKeyId = '',
        public string $sealCertificateSha256 = '',
        public ?AssuranceLevel $assuranceLevelReached = null,
        public ?array $validationReport = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $validationReport
     */
    public static function of(
        ArtifactKind $kind,
        string $bytes,
        string $disk,
        string $workspacePublicId,
        string $envelopePublicId,
        string $sealKeyId = '',
        string $sealCertificateSha256 = '',
        ?AssuranceLevel $assuranceLevelReached = null,
        ?array $validationReport = null,
    ): self {
        $sha256 = hash('sha256', $bytes);

        return new self(
            kind: $kind,
            bytes: $bytes,
            sha256: $sha256,
            byteCount: strlen($bytes),
            disk: $disk,
            key: ArtifactStorageKey::for($workspacePublicId, $envelopePublicId, $kind, $sha256),
            sealKeyId: $sealKeyId,
            sealCertificateSha256: $sealCertificateSha256,
            assuranceLevelReached: $assuranceLevelReached,
            validationReport: $validationReport,
        );
    }

    /**
     * Rebuild from what a previous run recorded, re-deriving the key from the digest.
     *
     * The key is rebuilt rather than trusted: it is a pure function of the workspace, the
     * envelope, the kind, and the digest, so recomputing it and comparing is a cheap check
     * that the stored row has not been edited to point somewhere else.
     *
     * @param  array<string, mixed>  $output
     */
    public static function fromRunOutput(array $output, string $workspacePublicId, string $envelopePublicId): self
    {
        $kind = ArtifactKind::from((string) $output['kind']);
        $sha256 = (string) $output['sha256'];
        $key = ArtifactStorageKey::for($workspacePublicId, $envelopePublicId, $kind, $sha256);

        if ($key->value !== (string) $output['path']) {
            throw new Exceptions\FinalizationException(
                'A recorded artifact key does not match the key its own digest produces, so the '
                .'recorded bytes are not reused. Nothing is published from it.',
            );
        }

        $report = $output['validation_report'] ?? null;
        $level = $output['assurance_level_reached'] ?? null;

        return new self(
            kind: $kind,
            bytes: '',
            sha256: $sha256,
            byteCount: (int) $output['bytes'],
            disk: (string) $output['disk'],
            key: $key,
            sealKeyId: (string) ($output['seal_key_id'] ?? ''),
            sealCertificateSha256: (string) ($output['seal_certificate_sha256'] ?? ''),
            assuranceLevelReached: is_string($level) ? AssuranceLevel::from($level) : null,
            validationReport: is_array($report) ? $report : null,
        );
    }

    /**
     * The shape stored on `finalization_runs.outputs`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'disk' => $this->disk,
            'path' => $this->key->value,
            'sha256' => $this->sha256,
            'bytes' => $this->byteCount,
            'seal_key_id' => $this->sealKeyId,
            'seal_certificate_sha256' => $this->sealCertificateSha256,
            'assurance_level_reached' => $this->assuranceLevelReached?->value,
            'validation_report' => $this->validationReport,
        ];
    }

    /**
     * The attributes of the `artifacts` row this becomes at publication.
     *
     * @return array<string, mixed>
     */
    public function rowAttributes(int $envelopeId, int $generation): array
    {
        return [
            'envelope_id' => $envelopeId,
            'kind' => $this->kind->value,
            'disk' => $this->disk,
            'path' => $this->key->value,
            'sha256' => $this->sha256,
            'bytes' => $this->byteCount,
            'generation' => $generation,
            'seal_key_id' => $this->sealKeyId,
            'seal_certificate_sha256' => $this->sealCertificateSha256,
            'assurance_level_reached' => $this->assuranceLevelReached?->value,
            'validation_report' => $this->validationReport,
        ];
    }
}
