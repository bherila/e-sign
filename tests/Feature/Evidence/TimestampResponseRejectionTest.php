<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\TimestampTokenRejectedException;
use App\Domain\Evidence\Sealing\TcLibPdfArtifactValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeTimestampAuthority;
use Tests\Support\SealingFixtures;
use Tests\TestCase;

/**
 * PAdES B-T fails closed on a bad timestamp response (issue #1, Stage 0).
 *
 * Every case runs offline and always: a local authority ({@see FakeTimestampAuthority}) behind
 * the production port mints genuine RFC 3161 tokens, and each failure mode is one fault on top
 * of a token that would otherwise be accepted. That the good one is accepted is asserted first,
 * so a refusal below is the fault being refused, not a fake authority that never worked.
 *
 * What is proved is the application's contract, not the library's internals: a bad response
 * raises {@see TimestampTokenRejectedException}, no artifact is returned, and B-T never quietly
 * becomes B-B. The checks themselves are tc-lib-pdf-sign's; the reason each was refused is
 * asserted, so a case cannot pass by failing for an unrelated reason.
 */
final class TimestampResponseRejectionTest extends TestCase
{
    public function test_a_genuine_token_for_the_request_is_embedded_and_reaches_b_t(): void
    {
        $tsa = new FakeTimestampAuthority(FakeTimestampAuthority::GOOD);

        $artifact = SealingFixtures::sealer(timestampAuthority: $tsa, allowSha1TimestampToken: false)
            ->seal(SealingFixtures::request(SealingFixtures::syntheticPdf(), AssuranceLevel::PadesBT));

        $this->assertCount(1, $tsa->requests);
        $this->assertSame(AssuranceLevel::PadesBT, $artifact->level);
        $this->assertSame(FakeTimestampAuthority::ENDPOINT, $artifact->timestampAuthority);

        $report = (new TcLibPdfArtifactValidator)->validate($artifact->pdf);

        $this->assertSame([], $report->failures);
        $this->assertTrue($report->hasSignatureTimestamp);
        $this->assertSame(AssuranceLevel::PadesBT, $report->reachedLevel());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badResponses(): array
    {
        return [
            'an empty body' => [FakeTimestampAuthority::EMPTY, 'Empty TSA response'],
            'bytes that are not a response' => [FakeTimestampAuthority::GARBAGE, 'Malformed ASN.1 length'],
            'a rejection status with no token' => [FakeTimestampAuthority::REJECTED, 'TSA request rejected'],
            'a token over other bytes' => [FakeTimestampAuthority::OTHER_IMPRINT, 'does not cover the requested bytes'],
            'a token answering another nonce' => [FakeTimestampAuthority::OTHER_NONCE, 'nonce does not match'],
            'a token whose signature was altered' => [FakeTimestampAuthority::TAMPERED_SIGNATURE, 'signature does not verify'],
            'a token from an expired TSA certificate' => [FakeTimestampAuthority::EXPIRED_TSA, 'did not cover the token genTime'],
            'a response with trailing bytes' => [FakeTimestampAuthority::TRAILING_BYTES, 'Invalid TSA response'],
        ];
    }

    #[DataProvider('badResponses')]
    public function test_a_bad_response_fails_the_seal_rather_than_downgrading(string $mode, string $reason): void
    {
        $tsa = new FakeTimestampAuthority($mode);

        try {
            SealingFixtures::sealer(timestampAuthority: $tsa, allowSha1TimestampToken: false)
                ->seal(SealingFixtures::request(SealingFixtures::syntheticPdf(), AssuranceLevel::PadesBT));

            $this->fail('A seal was produced from a bad timestamp response ('.$mode.').');
        } catch (TimestampTokenRejectedException $rejected) {
            $this->assertStringContainsString('was not reached and no artifact is published', $rejected->getMessage());
            $this->assertStringContainsString($reason, $rejected->getMessage());
        }

        // The authority was asked once: the bad answer was refused, not retried into a good one.
        $this->assertCount(1, $tsa->requests);
    }
}
