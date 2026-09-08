<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityUnreachableException;
use App\Domain\Evidence\Sealing\HttpTimestampAuthority;
use App\Domain\Evidence\Sealing\TcLibPdfArtifactValidator;
use Tests\Support\SealingFixtures;
use Tests\Support\TsaProbe;
use Tests\TestCase;

/**
 * PAdES B-T against a real public RFC 3161 authority.
 *
 * These tests reach the network, which the rest of the suite does not, so they
 * skip rather than fail when the authority is unreachable — an offline
 * workstation or a CI runner without egress is not a sealing defect. A skip is
 * visible in the runner output; it is never reported as a pass of the B-T gate.
 */
final class PadesTimestampTest extends TestCase
{
    public function test_it_obtains_and_embeds_a_real_rfc_3161_timestamp(): void
    {
        $endpoint = TsaProbe::firstReachable();

        if ($endpoint === null) {
            $this->markTestSkipped(
                'No public RFC 3161 timestamp authority is reachable from this host, so PAdES B-T '.
                'cannot be exercised here. Candidates tried: '.implode(', ', TsaProbe::candidateUrls()).'.'
            );
        }

        $artifact = SealingFixtures::sealer(
            timestampAuthority: new HttpTimestampAuthority(
                $endpoint,
                timeout: 30,
                allowPlaintextHttp: str_starts_with($endpoint, 'http://'),
            ),
        )->seal(SealingFixtures::request(SealingFixtures::syntheticPdf(), AssuranceLevel::PadesBT));

        $this->assertSame(AssuranceLevel::PadesBT, $artifact->level);
        $this->assertSame($endpoint, $artifact->timestampAuthority);

        $report = (new TcLibPdfArtifactValidator)->validate($artifact->pdf);

        $this->assertSame([], $report->failures);
        $this->assertTrue($report->isValid());
        $this->assertTrue($report->hasSignatureTimestamp);
        $this->assertSame(AssuranceLevel::PadesBT, $report->reachedLevel());
    }

    public function test_an_unreachable_authority_fails_closed_rather_than_downgrading(): void
    {
        // RFC 5737 documentation space: routable in form, never answering. The
        // policy lets it through, so the failure has to come from the transport.
        $authority = new HttpTimestampAuthority('https://203.0.113.10/tsr', timeout: 2);

        $authority->assertUsable();

        $this->expectException(TimestampAuthorityUnreachableException::class);

        SealingFixtures::sealer(timestampAuthority: $authority)
            ->seal(SealingFixtures::request(SealingFixtures::syntheticPdf(), AssuranceLevel::PadesBT));
    }
}
