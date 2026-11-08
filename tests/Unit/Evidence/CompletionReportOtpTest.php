<?php

declare(strict_types=1);

namespace Tests\Unit\Evidence;

use App\Domain\Evidence\Finalization\CompletionReport;
use App\Domain\Evidence\Finalization\FinalizationInput;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use PHPUnit\Framework\TestCase;

/**
 * The completion report names the one-time code behind an acceptance, when there was one
 * (issue #166), and says nothing about codes when there was not.
 */
final class CompletionReportOtpTest extends TestCase
{
    public function test_an_acceptance_verified_by_a_code_names_the_code_record(): void
    {
        $text = $this->reportText('01JSYNTHETICOTPCHALLENGE00');

        $this->assertStringContainsString('one-time code (email)     01JSYNTHETICOTPCHALLENGE00', $text);
    }

    public function test_an_acceptance_without_a_code_has_no_code_line(): void
    {
        $this->assertStringNotContainsString('one-time code', $this->reportText(null));
    }

    private function reportText(?string $otpChallenge): string
    {
        $input = new FinalizationInput(
            envelopePublicId: '01JSYNTHETICENVELOPE000000',
            workspacePublicId: '01JSYNTHETICWORKSPACE00000',
            envelopeTitle: 'Synthetic agreement',
            documentRevisionId: 1,
            documentRevisionPublicId: '01JSYNTHETICREVISION000000',
            documentSha256: str_repeat('a', 64),
            fieldSchemaSha256: str_repeat('b', 64),
            materialValuesSha256: str_repeat('c', 64),
            consentPolicyVersion: 'synthetic-v1',
            assuranceLevel: AssuranceLevel::PadesBB,
            valueDigests: [],
            values: [],
            attestations: [[
                'recipient' => '01JSYNTHETICRECIPIENT00000',
                'schema_recipient_id' => 'buyer',
                'name' => 'Dana Buyer',
                'email' => 'dana@buyer.example.test',
                'order_index' => 0,
                'signed_at' => '2026-11-08T00:00:00Z',
                'attestation' => '01JSYNTHETICATTESTATION000',
                'attestation_sha256' => str_repeat('d', 64),
                'prev_attestation_sha256' => null,
                'accepted_at' => '2026-11-08T00:00:00Z',
                'verification_method' => $otpChallenge === null ? 'email_link' : 'email_otp',
                'otp_challenge' => $otpChallenge,
                'document_sha256' => str_repeat('a', 64),
                'field_schema_sha256' => str_repeat('b', 64),
                'material_values_sha256' => str_repeat('c', 64),
                'consent_policy_version' => 'synthetic-v1',
                'session_ref_sha256' => str_repeat('e', 64),
            ]],
            capturedAt: '2026-11-08T00:00:00Z',
        );

        $report = CompletionReport::build($input, 'synthetic-key', str_repeat('f', 64));

        return implode("\n", array_column($report->lines, 'text'));
    }
}
