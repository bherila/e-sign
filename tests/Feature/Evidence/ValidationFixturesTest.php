<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Evidence\Sealing\HttpTimestampAuthority;
use App\Domain\Evidence\Sealing\SealedArtifact;
use Tests\Support\SealingFixtures;
use Tests\Support\TsaProbe;
use Tests\TestCase;

/**
 * Regenerates the artifacts that scripts/validate-seal.sh hands to pyHanko.
 *
 * Skipped unless `ESIGN_WRITE_VALIDATION_FIXTURES=1`, because every seal
 * carries a fresh signing time: writing on every run would leave the committed
 * fixtures permanently dirty and make a real change invisible in a diff.
 *
 *     ESIGN_WRITE_VALIDATION_FIXTURES=1 php artisan test --filter=ValidationFixturesTest
 *
 * The manifest it writes is the contract with the shell script: one line per
 * artifact, naming the outcome pyHanko must reach and the trust configuration
 * it must reach it under. Nothing in the script decides that from a filename.
 */
final class ValidationFixturesTest extends TestCase
{
    private const MANIFEST = 'manifest.tsv';

    /** Only the fixture root is trusted; the OS trust store is replaced. */
    private const TRUST_FIXTURE_ONLY = 'fixture-only';

    /** The fixture root is added to the OS trust store, which anchors a public TSA. */
    private const TRUST_FIXTURE_PLUS_SYSTEM = 'fixture-plus-system';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('ESIGN_WRITE_VALIDATION_FIXTURES') !== '1') {
            $this->markTestSkipped(
                'Set ESIGN_WRITE_VALIDATION_FIXTURES=1 to regenerate tests/Fixtures/validation.'
            );
        }
    }

    public function test_it_writes_the_independent_validation_artifacts(): void
    {
        $directory = SealingFixtures::validationPath();
        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        foreach (glob($directory.'/*.pdf') ?: [] as $stale) {
            unlink($stale);
        }

        $manifest = [];
        $input = SealingFixtures::syntheticPdf();
        $this->write('input-synthetic.pdf', $input);

        $sealed = SealingFixtures::sealer()->seal(SealingFixtures::request($input));
        $donor = SealingFixtures::seal(marker: 'B');

        $this->write('sealed-b-b.pdf', $sealed->pdf);
        $manifest[] = ['sealed-b-b.pdf', 'valid', self::TRUST_FIXTURE_ONLY, 'PAdES B-B, sealed by the fixture leaf under the fixture root.'];

        $negatives = [
            'negative-modified-content.pdf' => [
                SealingFixtures::modifyCoveredContent($sealed->pdf),
                'Page geometry changed inside the signed byte range, same file length.',
            ],
            'negative-truncated.pdf' => [
                SealingFixtures::truncate($sealed->pdf),
                'Tail removed, so the /ByteRange over-claims the file.',
            ],
            'negative-incremental-update.pdf' => [
                SealingFixtures::appendIncrementalUpdate($sealed->pdf),
                'An unexpected revision appended, redefining the page object.',
            ],
            'negative-forged-cms.pdf' => [
                SealingFixtures::forgeContents($sealed->pdf, $donor->pdf),
                'The CMS of another sealed document spliced into /Contents.',
            ],
        ];

        foreach ($negatives as $name => [$bytes, $note]) {
            $this->write($name, $bytes);
            $manifest[] = [$name, 'invalid', self::TRUST_FIXTURE_ONLY, $note];
        }

        $untrusted = SealingFixtures::seal(
            material: SealingFixtures::material(
                certificate: 'untrusted.test.crt',
                privateKey: 'untrusted.test.pkey',
                chain: '',
            ),
        );
        $this->write('negative-untrusted-signer.pdf', $untrusted->pdf);
        $manifest[] = [
            'negative-untrusted-signer.pdf',
            'invalid',
            self::TRUST_FIXTURE_ONLY,
            'Cryptographically sound but sealed with a key outside the trusted root.',
        ];

        $manifest = [...$manifest, ...$this->writeTimestampedArtifacts()];

        $this->writeManifest($manifest);

        $this->assertFileExists($directory.'/'.self::MANIFEST);
        $this->assertFileExists($directory.'/sealed-b-b.pdf');
    }

    /**
     * Seal at B-T against each reachable public authority.
     *
     * Two artifacts, because the two facts are different: freetsa.org is
     * reachable over HTTPS but its root is in no ordinary trust store, and
     * DigiCert's root is widely trusted but its endpoint is plaintext HTTP. The
     * first shows a cryptographically sound timestamp from an untrusted
     * authority; the second shows a trusted one.
     *
     * @return list<array{string, string, string, string}>
     */
    private function writeTimestampedArtifacts(): array
    {
        $manifest = [];
        $notes = [];

        $targets = [
            'sealed-b-t.pdf' => [
                'http://timestamp.digicert.com',
                'valid',
                self::TRUST_FIXTURE_PLUS_SYSTEM,
                'PAdES B-T. The TSA certificate chains to a publicly trusted root, so the timestamp is trusted as well as sound.',
            ],
            'sealed-b-t-untrusted-tsa.pdf' => [
                'https://freetsa.org/tsr',
                'invalid',
                self::TRUST_FIXTURE_ONLY,
                'PAdES B-T with a cryptographically sound timestamp from an authority whose root is trusted nowhere: judged invalid on TSA trust alone.',
            ],
        ];

        foreach ($targets as $name => [$endpoint, $expectation, $trust, $note]) {
            $artifact = $this->sealWithTimestamp($endpoint);

            if (! $artifact instanceof SealedArtifact) {
                $notes[] = $name.': skipped, '.$endpoint.' did not produce a timestamp on this run.';

                continue;
            }

            $this->write($name, $artifact->pdf);
            $manifest[] = [$name, $expectation, $trust, $note];
        }

        // Recorded rather than silently omitted: a missing B-T artifact has to be
        // visible to whoever reads the validation output. Absent when nothing was
        // skipped, which is what the shell script tests for.
        $skipped = SealingFixtures::validationPath('b-t-skipped.txt');
        if ($notes === []) {
            if (is_file($skipped)) {
                unlink($skipped);
            }
        } else {
            file_put_contents($skipped, implode("\n", $notes)."\n");
        }

        return $manifest;
    }

    private function sealWithTimestamp(string $endpoint): ?SealedArtifact
    {
        if (! TsaProbe::isReachable($endpoint)) {
            return null;
        }

        try {
            return SealingFixtures::sealer(
                timestampAuthority: new HttpTimestampAuthority(
                    $endpoint,
                    timeout: 30,
                    allowPlaintextHttp: str_starts_with($endpoint, 'http://'),
                ),
            )->seal(SealingFixtures::request(SealingFixtures::syntheticPdf(), AssuranceLevel::PadesBT));
        } catch (SealingException) {
            return null;
        }
    }

    private function write(string $name, string $bytes): void
    {
        file_put_contents(SealingFixtures::validationPath($name), $bytes);
    }

    /**
     * @param  list<array{string, string, string, string}>  $rows
     */
    private function writeManifest(array $rows): void
    {
        $lines = [
            '# Artifacts for scripts/validate-seal.sh. Regenerate with',
            '#   ESIGN_WRITE_VALIDATION_FIXTURES=1 php artisan test --filter=ValidationFixturesTest',
            '# Columns: file<TAB>expectation<TAB>trust-mode<TAB>note',
            '#   expectation: valid | invalid  (pyHanko exit status 0 | non-zero)',
            '#   trust-mode:  fixture-only          --trust-replace --trust root.test.crt',
            '#                fixture-plus-system   --trust root.test.crt, OS trust kept',
        ];

        foreach ($rows as $row) {
            $lines[] = implode("\t", $row);
        }

        file_put_contents(SealingFixtures::validationPath(self::MANIFEST), implode("\n", $lines)."\n");
    }
}
