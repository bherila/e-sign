<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

/**
 * What the completion report says, as content rather than as a document.
 *
 * Separating the content from the two renderings of it is the point: the same lines are
 * appended to the executed PDF as its final page and published on their own as the
 * `completion_report` artifact, and a report that disagreed with the page bound into the
 * agreement would be worse than having no report at all.
 *
 * ## Language
 *
 * Every phrasing rule in AGENTS.md ("Honest language") lands here, because this is the one
 * artifact a person actually reads:
 *
 *  - The humans provided electronic signatures and assent. The service sealed the result
 *    under its **own** certificate. The seal is not a per-signer certificate and this
 *    document says so in as many words.
 *  - This is a completion report, not an X.509 certificate. docs/HANDOFF.md section 8
 *    requires that distinction to be explicit, because "certificate" is exactly what a
 *    reader will otherwise assume a page like this is.
 *  - No claim of an eIDAS advanced or qualified signature, and no claim that the storage is
 *    tamper-proof or that the record is an independent witness.
 *
 * ## Timestamps
 *
 * Acceptance time, sealing time, and publication time are three different facts and are
 * never merged (docs/ARCHITECTURE.md, "Recipient progress is modelled independently").
 * Only acceptance times are on this page: it is written *before* the document is sealed, so
 * stating a sealing or publication time here would be a prediction. Those two live in the
 * evidence document, which is written afterwards.
 *
 * For the same reason the executed PDF's own digest is absent: the report is bound into the
 * document it would describe, and docs/HANDOFF.md section 8 rules out a self-referential
 * "final hash inside itself".
 */
final readonly class CompletionReport
{
    public const TITLE = 'Completion report';

    /** A heading line. */
    public const STYLE_HEADING = 'heading';

    /** An ordinary line. */
    public const STYLE_BODY = 'body';

    /** Small print: the disclaimers. */
    public const STYLE_NOTE = 'note';

    /** Vertical space. */
    public const STYLE_SPACER = 'spacer';

    /**
     * @param  list<array{style: string, text: string}>  $lines
     */
    public function __construct(
        public string $title,
        public array $lines,
    ) {}

    public static function build(FinalizationInput $input, string $sealKeyId, string $sealCertificateSha256): self
    {
        $lines = [
            ['style' => self::STYLE_NOTE, 'text' => 'This page is a report about an executed agreement. It is not a certificate,'],
            ['style' => self::STYLE_NOTE, 'text' => 'and it is not a signature. It describes what the service recorded.'],
            ['style' => self::STYLE_SPACER, 'text' => ''],
            ['style' => self::STYLE_HEADING, 'text' => 'Agreement'],
            ['style' => self::STYLE_BODY, 'text' => 'Title             '.self::clip($input->envelopeTitle, 58)],
            ['style' => self::STYLE_BODY, 'text' => 'Envelope          '.$input->envelopePublicId],
            ['style' => self::STYLE_BODY, 'text' => 'Reviewed revision '.$input->documentRevisionPublicId],
            ['style' => self::STYLE_BODY, 'text' => 'Document SHA-256  '.$input->documentSha256],
            ['style' => self::STYLE_BODY, 'text' => 'Field schema      '.$input->fieldSchemaSha256],
            ['style' => self::STYLE_BODY, 'text' => 'Agreed content    '.$input->materialValuesSha256],
            ['style' => self::STYLE_BODY, 'text' => 'Consent policy    '.$input->consentPolicyVersion],
            ['style' => self::STYLE_SPACER, 'text' => ''],
            ['style' => self::STYLE_HEADING, 'text' => 'Parties and acceptances'],
        ];

        if ($input->attestations === []) {
            $lines[] = ['style' => self::STYLE_BODY, 'text' => 'No acceptance was recorded.'];
        }

        foreach ($input->attestations as $index => $attestation) {
            $lines[] = [
                'style' => self::STYLE_BODY,
                'text' => sprintf(
                    '%d. %s <%s>',
                    $index + 1,
                    self::clip((string) $attestation['name'], 40),
                    self::clip((string) $attestation['email'], 40),
                ),
            ];
            $lines[] = [
                'style' => self::STYLE_BODY,
                'text' => '   accepted at (server time) '.$attestation['accepted_at'],
            ];
            $lines[] = [
                'style' => self::STYLE_BODY,
                'text' => '   verification              '.$attestation['verification_method'],
            ];
            $lines[] = [
                'style' => self::STYLE_BODY,
                'text' => '   attestation SHA-256       '.$attestation['attestation_sha256'],
            ];
        }

        $lines = [
            ...$lines,
            ['style' => self::STYLE_SPACER, 'text' => ''],
            ['style' => self::STYLE_HEADING, 'text' => 'Service seal'],
            ['style' => self::STYLE_BODY, 'text' => 'Assurance level   '.$input->assuranceLevel->value],
            ['style' => self::STYLE_BODY, 'text' => 'Seal key id       '.($sealKeyId === '' ? '(not recorded)' : $sealKeyId)],
            ['style' => self::STYLE_BODY, 'text' => 'Seal certificate  '.($sealCertificateSha256 === '' ? '(not recorded)' : $sealCertificateSha256)],
            ['style' => self::STYLE_SPACER, 'text' => ''],
            ['style' => self::STYLE_NOTE, 'text' => 'The people named above provided electronic signatures and assent. The service'],
            ['style' => self::STYLE_NOTE, 'text' => 'then sealed the completed document under its own organizational certificate,'],
            ['style' => self::STYLE_NOTE, 'text' => 'identified by the key id above. That seal is not a personal certificate held by'],
            ['style' => self::STYLE_NOTE, 'text' => 'any signer, and no claim is made that it is an eIDAS advanced or qualified'],
            ['style' => self::STYLE_NOTE, 'text' => 'electronic signature.'],
            ['style' => self::STYLE_SPACER, 'text' => ''],
            ['style' => self::STYLE_NOTE, 'text' => 'Publication is refused unless the sealed bytes are read back at the assurance'],
            ['style' => self::STYLE_NOTE, 'text' => 'level named above. Acceptance, sealing, and publication happen at different'],
            ['style' => self::STYLE_NOTE, 'text' => 'times; the sealing and publication times are recorded in the evidence document'],
            ['style' => self::STYLE_NOTE, 'text' => 'published alongside this agreement, which also lists what each digest covers.'],
        ];

        return new self(self::TITLE, $lines);
    }

    private static function clip(string $value, int $length): string
    {
        return mb_strlen($value, 'UTF-8') > $length
            ? mb_substr($value, 0, $length - 3, 'UTF-8').'...'
            : $value;
    }
}
