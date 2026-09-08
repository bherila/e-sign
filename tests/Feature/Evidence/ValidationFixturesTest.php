<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Evidence\Sealing\HttpTimestampAuthority;
use App\Domain\Evidence\Sealing\SealedArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FinalizationScenario;
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
    // The finalized artifact is built from a real signed envelope, so this class needs a
    // schema. Every other fixture here is pure bytes and would not.
    use RefreshDatabase;

    private const MANIFEST = 'manifest.tsv';

    /** Only the fixture root is trusted; the OS trust store is replaced. */
    private const TRUST_FIXTURE_ONLY = 'fixture-only';

    /** pyHanko must open the file and judge the signature invalid. */
    private const EXPECT_INVALID = 'invalid';

    /** pyHanko must refuse the file before it reaches the signature. */
    private const EXPECT_UNREADABLE = 'unreadable';

    /** The fixture root is added to the OS trust store, which anchors a public TSA. */
    private const TRUST_FIXTURE_PLUS_SYSTEM = 'fixture-plus-system';

    /** Only the rotation target's root is trusted; the fixture root is not. */
    private const TRUST_ROTATION_TARGET_ONLY = 'rotation-target-only';

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
                self::EXPECT_INVALID,
                'Page geometry changed inside the signed byte range, same file length.',
            ],
            'negative-byte-range-overclaim.pdf' => [
                SealingFixtures::overclaimByteRange($sealed->pdf),
                self::EXPECT_INVALID,
                'The /ByteRange claims past the end of a file that still parses, so the coverage rule '.
                'is what refuses it.',
            ],
            'negative-truncated.pdf' => [
                SealingFixtures::truncate($sealed->pdf),
                // Deliberately "unreadable", not "invalid": the trailer went
                // with the tail, so a validator never reaches the signature.
                // Labelling it "invalid" would let a parse error stand in for a
                // signature verdict and prove less than it appears to.
                self::EXPECT_UNREADABLE,
                'Tail removed with the trailer, so the file cannot be opened at all.',
            ],
            'negative-incremental-update.pdf' => [
                SealingFixtures::appendIncrementalUpdate($sealed->pdf),
                self::EXPECT_INVALID,
                'An unexpected revision appended, redefining the page object.',
            ],
            'negative-forged-cms.pdf' => [
                SealingFixtures::forgeContents($sealed->pdf, $donor->pdf),
                self::EXPECT_INVALID,
                'The CMS of another sealed document spliced into /Contents.',
            ],
        ];

        foreach ($negatives as $name => [$bytes, $expectation, $note]) {
            $this->write($name, $bytes);
            $manifest[] = [$name, $expectation, self::TRUST_FIXTURE_ONLY, $note];
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
            self::EXPECT_INVALID,
            self::TRUST_FIXTURE_ONLY,
            'Cryptographically sound but sealed with a key outside the trusted root.',
        ];

        $manifest[] = $this->writeFinalizedArtifact();
        $manifest = [...$manifest, ...$this->writeRotationArtifacts()];
        $manifest = [...$manifest, ...$this->writeTimestampedArtifacts()];

        $this->writeManifest($manifest);

        $this->assertFileExists($directory.'/'.self::MANIFEST);
        $this->assertFileExists($directory.'/sealed-b-b.pdf');
        $this->assertFileExists($directory.'/finalized-executed.pdf');
        $this->assertFileExists($directory.'/rotation-retired-key.pdf');
        $this->assertFileExists($directory.'/rotation-active-key.pdf');
    }

    /**
     * The artifact the staged publication actually produces, end to end.
     *
     * Everything else in this directory is a document sealed directly from a synthetic input.
     * This one goes through the whole of issue #28 — a signed envelope, the field values drawn
     * on the reviewed revision, the completion report appended, and the seal applied to the
     * result — so pyHanko is validating what a deployment publishes rather than what the
     * sealer can do in isolation.
     *
     * It is deterministic in the way that matters here: same fixture document, same fixture
     * key, same trust anchor, and the same `valid` verdict every run. It is *not* byte-stable
     * (a fresh signing time and fresh document identifiers each run), which is true of every
     * artifact in this directory and is why they are regenerated rather than diffed.
     *
     * @return array{string, string, string, string}
     */
    private function writeFinalizedArtifact(): array
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        $artifact = Artifact::query()
            ->where('envelope_id', $scenario->envelope->getKey())
            ->where('kind', ArtifactKind::ExecutedPdf->value)
            ->firstOrFail();

        $this->write('finalized-executed.pdf', (string) Storage::disk('documents')->get($artifact->path));

        return [
            'finalized-executed.pdf',
            'valid',
            self::TRUST_FIXTURE_ONLY,
            'The executed agreement produced by the staged finalization: reviewed revision, field '.
            'values, appended completion report, sealed at PAdES B-B under the fixture root.',
        ];
    }

    /**
     * The rotation drill, checked by something that shares no code with the sealer.
     *
     * `tests/Feature/Evidence/SealRotationDrillTest.php` proves in-process that an artifact
     * sealed under a retired key still verifies against that key's certificate. That is a
     * self-check: it uses the same library to verify that it used to sign, so it cannot find a
     * fault common to both directions. These four rows are the independent half.
     *
     * Two artifacts, four rows, because the claim has two halves and both need proving:
     *
     *  - the retired artifact is VALID under the retired key's anchor, and the active artifact
     *    is VALID under the active key's anchor — each verifies against its own certificate;
     *  - each is INVALID under the *other* anchor — which is what makes the first half mean
     *    something. Without the negative rows, a validator that trusted everything would
     *    produce the same two VALID verdicts.
     *
     * The two keys have different roots on purpose; tests/Fixtures/crypto/README.md says why.
     *
     * @return list<array{string, string, string, string}>
     */
    private function writeRotationArtifacts(): array
    {
        $retired = SealingFixtures::seal(marker: 'A');
        $active = SealingFixtures::seal(material: SealingFixtures::materialB(), marker: 'B');

        $this->write('rotation-retired-key.pdf', $retired->pdf);
        $this->write('rotation-active-key.pdf', $active->pdf);

        return [
            [
                'rotation-retired-key.pdf',
                'valid',
                self::TRUST_FIXTURE_ONLY,
                'Sealed under the RETIRED key ('.SealingFixtures::KEY_ID.'), validated against the retired '
                .'certificate\'s own anchor. A rotation does not invalidate what the old key sealed.',
            ],
            [
                'rotation-active-key.pdf',
                'valid',
                self::TRUST_ROTATION_TARGET_ONLY,
                'Sealed under the ACTIVE key after rotation ('.SealingFixtures::KEY_ID_B.'), validated '
                .'against the new certificate\'s anchor.',
            ],
            [
                'rotation-retired-key.pdf',
                self::EXPECT_INVALID,
                self::TRUST_ROTATION_TARGET_ONLY,
                'The retired artifact under the NEW key\'s anchor: refused. The two anchors discriminate, '
                .'so the VALID verdicts above are about the right certificate and not about a validator '
                .'that trusts everything.',
            ],
            [
                'rotation-active-key.pdf',
                self::EXPECT_INVALID,
                self::TRUST_FIXTURE_ONLY,
                'The active artifact under the RETIRED key\'s anchor: refused. The complement of the row '
                .'above.',
            ],
        ];
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

            // The row is written either way. An artifact that is simply absent
            // from the manifest drops out of the validator's tally silently,
            // which is how a run with no B-T evidence would come to look like a
            // B-T pass. Listed-and-skipped is visible; unlisted is not.
            $manifest[] = [$name, $expectation, $trust, $note];

            if (! $artifact instanceof SealedArtifact) {
                // "<file>: reason" — validate-seal.sh matches on the "<file>:"
                // prefix to tell a skipped artifact from a forgotten one.
                $notes[] = $name.': skipped, '.$endpoint.' did not produce a timestamp on this run.';

                continue;
            }

            $this->write($name, $artifact->pdf);
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
            '#   expectation: valid       pyHanko opens the file and judges the signature VALID',
            '#                invalid     pyHanko opens the file and judges the signature INVALID',
            '#                unreadable  pyHanko refuses the file before reaching the signature',
            '#   trust-mode:  fixture-only          --trust-replace --trust root.test.crt',
            '#                fixture-plus-system   --trust root.test.crt, OS trust kept',
            '#                rotation-target-only  --trust-replace --trust root-b.test.crt',
            '# A file may appear more than once, under different trust modes: that is how the',
            '# rotation rows prove each artifact verifies against its own key and not the other.',
        ];

        foreach ($rows as $row) {
            $lines[] = implode("\t", $row);
        }

        file_put_contents(SealingFixtures::validationPath(self::MANIFEST), implode("\n", $lines)."\n");
    }
}
