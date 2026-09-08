<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Delivery\Webhooks\TextRedactor;
use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\CompletionReportDocument;
use App\Domain\Evidence\Finalization\EnvelopeFinalizer;
use App\Domain\Evidence\Finalization\ExecutedDocumentRenderer;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Support\Facades\Storage;

/**
 * An envelope that has actually been signed by two people and is sitting in `finalizing`,
 * with real document bytes behind it and the fixture seal material configured.
 *
 * Everything is synthetic (AGENTS.md): the document is the committed
 * `multi-page-mixed-size` fixture, the parties are `SigningFixtures`' example buyer and
 * seller, and the key material is the generated fixture key that signs nothing real.
 *
 * The point of building it through the real state machine rather than by inserting rows is
 * that the finalization suite is about what happens *after* two genuine acceptances — the
 * attestations, their chained digests, and the frozen values all have to be the ones the
 * signing rules actually produced, or the evidence being tested is evidence of nothing.
 */
final class FinalizationScenario
{
    /** Three pages, three different sizes: enough for both signers' fields plus a spare. */
    public const DOCUMENT_FIXTURE = 'multi-page-mixed-size';

    public readonly SigningScenario $signing;

    public readonly Envelope $envelope;

    private function __construct(AssuranceLevel $level, bool $reset)
    {
        // `Storage::fake()` *clears* the disk, and `configureSeal()` puts the active key back
        // to fixture key A. Both are what a single-scenario test wants and both are wrong for
        // the second envelope of the rotation drill, which has to keep the first envelope's
        // artifacts on disk and must be sealed under whatever key the test has rotated to.
        if ($reset) {
            Storage::fake('documents');

            self::configureSeal();
        }

        $this->signing = SigningScenario::create(
            revisionBytes: PdfFixtures::bytes(self::DOCUMENT_FIXTURE),
        )->bind();

        $envelope = $this->signing->sent(['assurance_level' => $level->value]);

        $this->signing->signAs($envelope, $this->signing->recipient($envelope, 'buyer'), 'session-buyer');
        $envelope->refresh();
        $this->signing->signAs($envelope, $this->signing->recipient($envelope, 'seller'), 'session-seller');

        $this->envelope = $envelope->refresh();

        if ($this->envelope->state !== EnvelopeState::Finalizing) {
            throw new \RuntimeException(
                'The scenario did not reach finalizing; it is in '.$this->envelope->state->value.'.',
            );
        }
    }

    /**
     * @param  bool  $reset  Re-fake the documents disk and reset the seal configuration.
     *                       Pass false for a second scenario in the same test, which needs the
     *                       first one's artifacts to survive and the current seal key to stand.
     */
    public static function signed(AssuranceLevel $level = AssuranceLevel::PadesBB, bool $reset = true): self
    {
        return new self($level, $reset);
    }

    /**
     * Point the seal configuration at the generated fixture key material.
     *
     * No TSA: the default scenario seals at B-B, and a test that wants B-T sets the URL
     * itself so the "requested B-T with no authority" case stays explicit.
     */
    public static function configureSeal(): void
    {
        config([
            'esign.seal.key_id' => SealingFixtures::KEY_ID,
            'esign.seal.certificate_path' => base_path('tests/Fixtures/crypto/seal.test.crt'),
            'esign.seal.private_key_path' => base_path('tests/Fixtures/crypto/seal.test.pkey'),
            'esign.seal.chain_path' => base_path('tests/Fixtures/crypto/root.test.crt'),
            'esign.seal.digest_algorithm' => 'sha256',
            'esign.tsa.url' => '',
        ]);
    }

    /**
     * A finalizer wired from the container, with individual collaborators swapped out.
     *
     * Resolving the rest through the container keeps the service provider's wiring on the
     * tested path, which matters: a test that hand-built every dependency would pass even if
     * the application wired the finalizer to nothing.
     */
    public function finalizer(
        ?ArtifactStore $store = null,
        ?PdfSealer $sealer = null,
        ?ExecutedDocumentRenderer $renderer = null,
        ?EnvelopeEventSink $sink = null,
    ): EnvelopeFinalizer {
        return new EnvelopeFinalizer(
            stateMachine: $sink === null
                ? app(EnvelopeStateMachine::class)
                : new EnvelopeStateMachine($sink, $this->signing->assurance),
            sealer: $sealer ?? app(PdfSealer::class),
            validator: app(ArtifactValidator::class),
            sealIdentity: app(SealIdentity::class),
            renderer: $renderer ?? new ExecutedDocumentRenderer(app(PdfAssembler::class)),
            completionReport: app(CompletionReportDocument::class),
            store: $store ?? app(ArtifactStore::class),
            documents: app(DocumentBlobStore::class),
            redactor: app(TextRedactor::class),
            disk: 'documents',
        );
    }

    public function machine(): EnvelopeStateMachine
    {
        return $this->signing->machine();
    }
}
