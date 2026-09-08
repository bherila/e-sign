<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\ValidationReport;
use App\Domain\Signing\Fields\CanonicalValue;

/**
 * The machine-readable evidence document published alongside an executed agreement.
 *
 * docs/HANDOFF.md section 8 asks for "a versioned canonical evidence encoding" and, in the
 * same paragraph, for the thing that is usually missing from one: **"Define exactly which
 * object each digest covers."** Every digest in this document therefore appears with a
 * `covers` sentence next to it, and `digest_index` repeats the whole set in one flat list so
 * a verifier does not have to know the document's shape to check it.
 *
 * ## Three timestamps, never merged
 *
 * - **acceptance** — when a person assented, on the server clock, one per attestation. This
 *   is the only one that says anything about a human.
 * - **sealing** — when the service applied its seal. For PAdES B-T there is additionally a
 *   timestamp *token* from an external authority, and the two are different claims: the
 *   token is evidence the bytes existed by that time, the sealing time is this host's clock.
 * - **publication** — when the artifact rows committed. Deliberately `null` here, with the
 *   reason stated in the document itself: this file is written and durably stored *before*
 *   publication, so a publication time inside it would be a prediction. It is recorded on the
 *   artifact row and in the `signing_request.completed` audit event.
 *
 * ## What is not here
 *
 * Storage disks and object keys, which never leave the application (docs/BLOB_STORAGE.md),
 * and the evidence document's own digest, which cannot be inside itself. The exporter's
 * manifest carries that one.
 */
final readonly class EvidenceDocument
{
    /** The document format version, as the file itself reports it. */
    public const VERSION = 1;

    public const ENCODING = 'esign.evidence.v1';

    /**
     * @param  array<string, string>  $artifactDigests  Artifact kind value => SHA-256 of its bytes.
     * @param  array<string, int>  $artifactSizes  Artifact kind value => byte count.
     */
    public static function build(
        FinalizationInput $input,
        int $generation,
        string $sealKeyId,
        string $sealCertificateSha256,
        string $sealSubject,
        string $sealDigestAlgorithm,
        ?string $timestampAuthority,
        AssuranceLevel $requested,
        ?AssuranceLevel $reached,
        ValidationReport $validation,
        string $sealedAt,
        array $artifactDigests,
        array $artifactSizes,
    ): string {
        $digestIndex = [
            [
                'name' => 'document_sha256',
                'algorithm' => 'sha256',
                'value' => $input->documentSha256,
                'covers' => 'The complete bytes of the reviewed document revision, as retained and as every '
                    .'attestation binds it. Not the executed PDF.',
            ],
            [
                'name' => 'field_schema_sha256',
                'algorithm' => 'sha256',
                'value' => $input->fieldSchemaSha256,
                'covers' => 'The canonical JSON of the field schema copied onto the envelope at creation: '
                    .'recipients, signing order, and every field definition with its native rectangle.',
            ],
            [
                'name' => 'material_values_sha256',
                'algorithm' => 'sha256',
                'value' => $input->materialValuesSha256,
                'covers' => 'The canonical encoding of the agreement\'s shared content values only — the '
                    .'fields a signer\'s assent is bound to. Signer-specific values are excluded by design.',
            ],
        ];

        foreach ($input->valueDigests as $value) {
            $digestIndex[] = [
                'name' => 'field_value_sha256:'.$value['field'],
                'algorithm' => 'sha256',
                'value' => $value['value_sha256'],
                'covers' => 'The canonical JSON encoding of the stored value of field "'.$value['field'].'".',
            ];
        }

        foreach ($input->attestations as $attestation) {
            $digestIndex[] = [
                'name' => 'attestation_sha256:'.$attestation['attestation'],
                'algorithm' => 'sha256',
                'value' => $attestation['attestation_sha256'],
                'covers' => 'One acceptance: the envelope and recipient identifiers, the document, schema and '
                    .'material-value digests, the consent version displayed, the session it was given in, the '
                    .'server acceptance time, the verification method, the minimized client evidence, and the '
                    .'previous acceptance\'s digest on this envelope.',
            ];
        }

        foreach ($artifactDigests as $kind => $digest) {
            $digestIndex[] = [
                'name' => 'artifact_sha256:'.$kind,
                'algorithm' => 'sha256',
                'value' => $digest,
                'covers' => 'The complete published bytes of the '.str_replace('_', ' ', (string) $kind)
                    .' artifact, computed outside that artifact.',
            ];
        }

        $document = [
            'evidence_version' => self::VERSION,
            'encoding' => self::ENCODING,

            'envelope' => [
                'id' => $input->envelopePublicId,
                'workspace' => $input->workspacePublicId,
                'title' => $input->envelopeTitle,
                'consent_policy_version' => $input->consentPolicyVersion,
                'finalization_generation' => $generation,
            ],

            'document' => [
                'reviewed_revision' => $input->documentRevisionPublicId,
                'sha256' => $input->documentSha256,
                'covers' => 'The reviewed revision as retained byte-for-byte. The executed PDF is a new '
                    .'artifact built from it and is listed under "artifacts".',
            ],

            'field_schema' => [
                'sha256' => $input->fieldSchemaSha256,
                'covers' => 'The canonical JSON of the copied field schema.',
            ],

            'material_values' => [
                'sha256' => $input->materialValuesSha256,
                'covers' => 'The canonical encoding of the shared agreement content each acceptance is bound to.',
            ],

            'field_values' => $input->valueDigests,

            'attestation_chain' => $input->attestations,

            'artifacts' => array_values(array_map(
                static fn (string $kind): array => [
                    'kind' => $kind,
                    'sha256' => $artifactDigests[$kind] ?? '',
                    'bytes' => $artifactSizes[$kind] ?? 0,
                ],
                array_keys($artifactDigests),
            )),

            'seal' => [
                'key_id' => $sealKeyId,
                'certificate_sha256' => $sealCertificateSha256,
                'certificate_subject' => $sealSubject,
                'digest_algorithm' => $sealDigestAlgorithm,
                'assurance_level_requested' => $requested->value,
                'assurance_level_reached' => $reached?->value,
                'timestamp_authority' => $timestampAuthority,
                'note' => 'The seal is the service\'s own organizational certificate applied to the executed '
                    .'document. It is not a personal certificate held by any signer, and no eIDAS advanced or '
                    .'qualified electronic signature is claimed.',
            ],

            'validation_report' => self::validationReport($validation),

            'timestamps' => [
                'acceptance' => array_values(array_map(
                    static fn (array $attestation): array => [
                        'attestation' => $attestation['attestation'],
                        'recipient' => $attestation['recipient'],
                        'accepted_at' => $attestation['accepted_at'],
                        'source' => 'server clock at the moment assent was recorded',
                    ],
                    $input->attestations,
                )),
                'sealing' => [
                    'sealed_at' => $sealedAt,
                    'source' => 'server clock when the seal was applied',
                    'timestamp_token' => $validation->hasSignatureTimestamp
                        ? 'an RFC 3161 signature timestamp token is present in the CMS; it is an external '
                            .'authority\'s claim about when these bytes existed, which is a different claim '
                            .'from the acceptance times above'
                        : null,
                ],
                'publication' => null,
                'publication_note' => 'This document is written and durably stored before the artifact rows are '
                    .'committed, so a publication time inside it would be a prediction. The publication instant '
                    .'is recorded on the artifact row and in the signing_request.completed audit event.',
            ],

            'digest_index' => $digestIndex,

            'limitations' => [
                'The acceptance chain lives in a database this application can write to, so it is not an '
                    .'independent witness. Off-host checkpoints and signer copies are what make it one.',
                'A validator running in this process shares the signing library, so it cannot find a fault '
                    .'common to both directions. Independent validation is a separate, external step.',
                'A cryptographically valid timestamp token is not a trusted one; trusting an authority is a '
                    .'relying party\'s decision, not this document\'s claim.',
            ],
        ];

        return CanonicalValue::encode($document);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validationReport(ValidationReport $report): array
    {
        return [
            'signed' => $report->signed,
            'sub_filter' => $report->subFilter,
            'digest_algorithm' => $report->digestAlgorithm,
            'covers_whole_file' => $report->coversWholeFile,
            'cryptographically_sound' => $report->cryptographicallySound,
            'has_signature_timestamp' => $report->hasSignatureTimestamp,
            'revisions' => $report->revisions,
            'signer_subject' => $report->signerSubject,
            'signer_fingerprint' => $report->signerFingerprint,
            'failures' => $report->failures,
            'reached_level' => $report->reachedLevel()?->value,
        ];
    }
}
