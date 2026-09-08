<?php

declare(strict_types=1);

namespace Tests\Support\SyntheticConsumer;

use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Webhooks\DeliveryState;
use App\Domain\Delivery\Webhooks\Jobs\DeliverWebhook;
use App\Domain\Delivery\Webhooks\Models\WebhookDelivery;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Delivery\Webhooks\TextRedactor;
use App\Domain\Delivery\Webhooks\WebhookEndpointManager;
use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\CompletionReportDocument;
use App\Domain\Evidence\Finalization\EnvelopeFinalizer;
use App\Domain\Evidence\Finalization\ExecutedDocumentRenderer;
use App\Domain\Evidence\Finalization\Jobs\FinalizeEnvelope;
use App\Domain\Identity\Credentials\IssuedServiceCredential;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Sessions\SigningCookie;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\FirmaFacadeScenario;
use Tests\Support\PdfFixtures;
use Tests\Support\SigningFixtures;
use Tests\Support\SyntheticImages;

/**
 * A test double of the **consumer application**, not of this one.
 *
 * Everything below it is real: the Firma-compatible facade over the real HTTP kernel, the real
 * state machine, the real invitation issuer, the real guest signing pages, the real
 * transactional outbox, the real webhook signer and delivery job, the real finalizer and
 * sealer, and the real mail outbox on the `array` mailer. What is faked is only what would
 * otherwise leave the process — HTTP egress, the storage backend, the mail transport, the
 * timestamp authority — and the queue worker's *timing*, so a test can drive the worker
 * instead of racing it.
 *
 * ## The three seams, and why each is where it is
 *
 * **HTTP egress.** `Http::preventStrayRequests()` is on for the whole run and the only fake
 * registered matches this consumer's own endpoint. Anything aimed anywhere else — a Firma
 * host, a DocuSign host, a CDN, an analytics beacon — has no matching fake and raises
 * immediately. That is the "block Firma/DocuSign hosts" half of release gate 12, enforced for
 * every host at once rather than for a list somebody has to keep up to date. Deliveries that
 * do match are handed to the consumer's receiver route through the same HTTP kernel, so the
 * signature is verified over the bytes that were actually on the wire.
 *
 * **The webhook worker.** Only {@see DeliverWebhook} is faked on the queue. Everything else —
 * the outbox fan-out, the mail scheduler, the mailer job, finalization — still runs on the
 * synchronous queue, which honours `afterCommit()`. Deliveries therefore accumulate as real
 * `webhook_deliveries` rows and {@see drainWebhooks()} runs them exactly as a worker would:
 * only the ones that are due, in an order the test chooses. Without this, delivery would
 * happen *inside* the facade request that caused it, which is not where a worker runs and
 * would make a nested kernel call out of every event.
 *
 * **Storage and mail.** `documents` is a faked disk and the mailer is `array`. Both are
 * swapped by configuration alone, with no code branching on either, which is the swappability
 * half of gate 12; {@see useDocumentsDisk()} exists so a test can prove the second half by
 * running the same flow onto a different backend.
 *
 * The one thing this harness does that no production code does is **dispatch
 * {@see FinalizeEnvelope}**. Nothing in `app/` dispatches it today: an envelope that reaches
 * `finalizing` waits for an operator or an integration to start the work. The consumer plays
 * that part here, and `tests/EndToEnd/README.md` records it as a gap rather than hiding it.
 *
 * All data is synthetic (AGENTS.md): `.test` and `.example.test` addresses that no resolver
 * answers, committed PDF fixtures, and the generated fixture seal key.
 */
final class SyntheticConsumer
{
    /** The facade's base path, verbatim from the published contract. */
    public const BASE = '/functions/v1/signing-request-api/signing-requests';

    /** Where the consumer listens. Registered as a route inside the test. */
    public const WEBHOOK_PATH = '/__consumer/webhook';

    /**
     * The consumer's callback URL.
     *
     * RFC 5737 TEST-NET-3 over HTTPS: public as far as `DestinationPolicy` is concerned — so
     * the real SSRF checks run and pass rather than being bypassed — and unroutable in
     * reality, so a fake that failed to match could not reach anything.
     */
    public const WEBHOOK_URL = 'https://203.0.113.10'.self::WEBHOOK_PATH;

    /** Deliver the request to the receiver, then lose the response. */
    public const DROP_RESPONSE = 'drop-response';

    /** Never reach the receiver at all. */
    public const DROP_REQUEST = 'drop-request';

    public readonly FirmaFacadeScenario $scenario;

    public readonly IssuedServiceCredential $credential;

    public readonly WebhookEndpoint $endpoint;

    public readonly ConsumerWebhookReceiver $receiver;

    /** @var list<string> One transport behaviour per attempt, consumed in order. */
    private array $transportBehaviours = [];

    private function __construct(private readonly TestCase $test) {}

    /**
     * Stand the consumer up: credential, endpoint, receiver route, and the egress fence.
     */
    public static function install(TestCase $test): self
    {
        $consumer = new self($test);

        $consumer->bootApplicationUnderTest();
        $consumer->bootConsumer();

        return $consumer;
    }

    /* ============================================================== the consumer's side */

    /**
     * The consumer's syntax: the raw API key in `Authorization`, with no scheme at all.
     *
     * `docs/HANDOFF.md` §10 makes accepting this a requirement rather than a courtesy, so it
     * is what this harness sends everywhere. The Bearer form is the variant, and the facade's
     * own auth suite covers it.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public function headers(array $extra = []): array
    {
        return ['Authorization' => $this->credential->secret] + $extra;
    }

    /* -------------------------------------------------------------- calling the facade */

    /**
     * @param  array<string, mixed>  $body
     */
    public function create(array $body): TestResponse
    {
        return $this->test->postJson(self::BASE, $body, $this->headers());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function createAndSend(array $body): TestResponse
    {
        return $this->test->postJson(self::BASE.'/create-and-send', $body, $this->headers());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function patch(string $id, array $body): TestResponse
    {
        return $this->test->patchJson(self::BASE.'/'.$id, $body, $this->headers());
    }

    public function send(string $id): TestResponse
    {
        return $this->test->postJson(self::BASE.'/'.$id.'/send', [], $this->headers());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function cancel(string $id, array $body = []): TestResponse
    {
        return $this->test->postJson(self::BASE.'/'.$id.'/cancel', $body, $this->headers());
    }

    /**
     * The polling response. What the consumer's reconciliation loop reads.
     *
     * @return array<string, mixed>
     */
    public function poll(string $id): array
    {
        return $this->test->getJson(self::BASE.'/'.$id, $this->headers())->assertOk()->json();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function users(string $id): array
    {
        return $this->test->getJson(self::BASE.'/'.$id.'/users', $this->headers())->assertOk()->json('results');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fields(string $id): array
    {
        return $this->test->getJson(self::BASE.'/'.$id.'/fields', $this->headers())->assertOk()->json('results');
    }

    /**
     * @return array<string, mixed>
     */
    public function download(string $id): array
    {
        return $this->test->getJson(self::BASE.'/'.$id.'/download', $this->headers())->assertOk()->json();
    }

    /**
     * Follow a `download_url` the way a consumer's worker does: no API key, just the link.
     */
    public function fetch(string $url): TestResponse
    {
        return $this->test->get($url);
    }

    /** The native evidence export, on the same credential. */
    public function evidenceBundle(string $id): TestResponse
    {
        return $this->test->get('/api/v1/envelopes/'.$id.'/evidence-bundle', $this->headers());
    }

    /* ----------------------------------------------------------------- reading the DB */

    /**
     * A published template version over this consumer's document revision.
     *
     * @param  array<string, mixed>|null  $schema  Defaults to the sequential two-signer fixture.
     */
    public function publishedTemplate(?array $schema = null, string $name = 'Synthetic platform NDA template'): TemplateVersion
    {
        return $this->scenario->publishedTemplate($schema, $name);
    }

    public function envelope(string $publicId): Envelope
    {
        return Envelope::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * The recipient row of `/users` for one address.
     *
     * @return array<string, mixed>
     */
    public function user(string $id, string $email): array
    {
        foreach ($this->users($id) as $user) {
            if (strcasecmp((string) $user['email'], $email) === 0) {
                return $user;
            }
        }

        throw new RuntimeException('No recipient '.$email.' on signing request '.$id.'.');
    }

    /* ================================================================== the guest flow */

    /**
     * The invitation URL for one address, read out of the queued message.
     *
     * This is the only place a token can be obtained, and deliberately so: the plaintext
     * exists once, in the URL `InvitationIssuer` returns, and is never stored. Reading it out
     * of the mail the application actually queued is exactly what the recipient's mail client
     * does, and it means the link under test is the link that was sent — not one the test
     * minted for itself.
     */
    public function invitationUrlFor(string $email): string
    {
        $mail = OutboundMail::query()
            ->where('kind', MailKind::Invitation)
            ->where('to_email', $email)
            ->orderByDesc('id')
            ->first();

        if ($mail === null) {
            throw new RuntimeException('No invitation was queued for '.$email.'.');
        }

        $url = $mail->context['action_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('The invitation queued for '.$email.' carried no signing URL.');
        }

        return $url;
    }

    /** True when a live invitation has been mailed to this address. */
    public function hasInvitationFor(string $email): bool
    {
        return OutboundMail::query()
            ->where('kind', MailKind::Invitation)
            ->where('to_email', $email)
            ->exists();
    }

    /**
     * Open the signing page as this recipient, without signing anything.
     *
     * A GET and nothing else: `AGENTS.md` requires that a mail scanner following the link
     * changes nothing, and the harness follows the link the same way one would.
     */
    public function openInvitation(string $email): TestResponse
    {
        return $this->test->call('GET', $this->landingPath($email));
    }

    /**
     * Sign as one recipient, over HTTP, exactly as a person does.
     *
     * Landing GET, Continue POST, values POST, accept POST — four requests through the real
     * kernel, carrying the encrypted session cookie forward verbatim between them. Nothing
     * here reaches into the state machine: the values are the ones the facade's own `/fields`
     * response says this recipient owes, and the acceptance quotes the digest and version the
     * page was rendered from.
     *
     * @param  array<string, mixed>  $overrides  Values to use instead of the generated ones.
     */
    public function signAs(string $envelopeId, string $email, array $overrides = []): void
    {
        $envelope = $this->envelope($envelopeId);
        $landing = $this->landingPath($email);

        $this->test->call('GET', $landing)->assertOk();

        $start = $this->test->call('POST', $landing.'/start');
        $start->assertRedirect('/sign/'.$envelope->public_id.'/session');

        $cookie = $start->getCookie(SigningCookie::NAME, false);

        if ($cookie === null) {
            throw new RuntimeException('Starting a signing session issued no cookie for '.$email.'.');
        }

        $jar = [SigningCookie::NAME => (string) $cookie->getValue()];
        $session = '/sign/'.$envelope->public_id.'/session';

        [$values, $signatureFieldIds] = $this->valuesOwedBy($envelopeId, $email);
        $values = array_replace($values, $overrides);

        $saved = $this->test->call(
            'POST',
            $session.'/values',
            [],
            $jar,
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['values' => $values], JSON_THROW_ON_ERROR),
        );
        $saved->assertOk();

        $this->test->call('POST', $session.'/accept', [
            'consent_accepted' => '1',
            'intent_confirmed' => '1',
            'consent_version' => $this->envelope($envelopeId)->consent_policy_version,
            'reviewed_material_sha256' => (string) $saved->json('reviewed.material_values_sha256'),
            'reviewed_envelope_version' => (int) $saved->json('reviewed.envelope_version'),
            'signature_field_ids' => $signatureFieldIds,
        ], $jar)->assertRedirect($session);
    }

    /**
     * What one recipient still owes, derived from the facade's own `/fields` response.
     *
     * Read-only fields are the sender's, and the one field type the service fills in from the
     * recipient's own attestation instant (`date_signing_default`) is nobody's to submit —
     * which is the same rule `accept()` applies when it checks for missing values.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function valuesOwedBy(string $envelopeId, string $email): array
    {
        $recipientId = (string) $this->user($envelopeId, $email)['id'];
        $values = [];
        $signatures = [];

        foreach ($this->fields($envelopeId) as $field) {
            if ($field['recipient_id'] !== $recipientId) {
                continue;
            }

            if (in_array($field['field_type'], ['signature', 'initial'], true)) {
                $signatures[] = (string) $field['id'];
            }

            if ($field['required'] !== true || $field['read_only'] === true || $field['date_signing_default'] === true) {
                continue;
            }

            $values[(string) $field['id']] = self::sampleValueFor((string) $field['field_type']);
        }

        return [$values, $signatures];
    }

    /** A value of the right shape for a profile field type. All synthetic. */
    public static function sampleValueFor(string $firmaFieldType): mixed
    {
        return match ($firmaFieldType) {
            'checkbox' => true,
            'signature', 'initial' => SyntheticImages::pngDataUrl(),
            'date' => '2026-02-01',
            default => 'Synthetic '.$firmaFieldType.' value',
        };
    }

    private function landingPath(string $email): string
    {
        return (string) parse_url($this->invitationUrlFor($email), PHP_URL_PATH);
    }

    /* ================================================================== the worker side */

    /**
     * Run the finalization job, which is what a queue worker does with it.
     *
     * @param  EnvelopeFinalizer|null  $finalizer  Bound for the duration of the run, so a test
     *                                             can interrupt a worker mid-publication.
     */
    public function runFinalizationWorker(string $envelopeId, ?EnvelopeFinalizer $finalizer = null): void
    {
        if ($finalizer !== null) {
            app()->instance(EnvelopeFinalizer::class, $finalizer);
        }

        try {
            FinalizeEnvelope::dispatch($envelopeId);
        } finally {
            if ($finalizer !== null) {
                app()->forgetInstance(EnvelopeFinalizer::class);
            }
        }
    }

    /**
     * A finalizer wired from the container with individual collaborators swapped.
     *
     * Resolving the rest through the container keeps the service provider's wiring on the
     * tested path, which matters: a finalizer whose every dependency the test hand-built would
     * pass even if the application wired the real one to nothing.
     */
    public function finalizerWith(?ArtifactStore $store = null, ?EnvelopeEventSink $sink = null): EnvelopeFinalizer
    {
        return new EnvelopeFinalizer(
            stateMachine: $sink === null
                ? app(EnvelopeStateMachine::class)
                : new EnvelopeStateMachine($sink, app(AssurancePolicyCheck::class)),
            sealer: app(PdfSealer::class),
            validator: app(ArtifactValidator::class),
            sealIdentity: app(SealIdentity::class),
            renderer: new ExecutedDocumentRenderer(app(PdfAssembler::class)),
            completionReport: app(CompletionReportDocument::class),
            store: $store ?? app(ArtifactStore::class),
            documents: app(DocumentBlobStore::class),
            redactor: app(TextRedactor::class),
            disk: 'documents',
        );
    }

    /** Put a visibly failed finalization back in the queue, as an operator would. */
    public function retryFinalization(string $envelopeId): void
    {
        app(EnvelopeStateMachine::class)->retryFinalization($this->envelope($envelopeId));
    }

    /**
     * Deliver every webhook attempt that is due, the way a worker would.
     *
     * @param  bool  $reverse  Deliver the due attempts newest first. Two events recorded
     *                         seconds apart really can arrive in the wrong order — a retry is
     *                         all it takes — so the receiver has to survive it.
     * @return int How many attempts were run.
     */
    public function drainWebhooks(bool $reverse = false): int
    {
        $due = WebhookDelivery::query()
            ->where('state', DeliveryState::Pending)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($reverse) {
            $due = array_reverse($due);
        }

        foreach ($due as $id) {
            app()->call([new DeliverWebhook((int) $id), 'handle']);
        }

        return count($due);
    }

    /** Attempts that exist but are not due yet — the retry schedule, waiting. */
    public function scheduledRetries(): int
    {
        return WebhookDelivery::query()
            ->where('state', DeliveryState::Pending)
            ->where('next_attempt_at', '>', now())
            ->count();
    }

    /**
     * Make the next delivery attempt fail at the transport rather than at the receiver.
     *
     * {@see DROP_RESPONSE} is the case the profile calls out by name: the receiver processed
     * the event and the connection dropped before the answer, so the sender cannot know and
     * retries. {@see DROP_REQUEST} never reaches the receiver at all.
     */
    public function nextDeliveryDrops(string $behaviour = self::DROP_RESPONSE): void
    {
        $this->transportBehaviours[] = $behaviour;
    }

    /* ================================================================= egress and disks */

    /**
     * Every request the application made through the HTTP client during this run.
     *
     * @return list<string>
     */
    public function outboundUrls(): array
    {
        return collect(Http::recorded())
            ->map(static fn (array $pair): string => $pair[0]->url())
            ->values()
            ->all();
    }

    /**
     * Every request the application put on the wire, as the client saw it.
     *
     * The headers here are the wire's, not the receiver's reading of them, which is what a
     * test asserting on the signature scheme itself wants.
     *
     * @return list<ClientRequest>
     */
    public function sentRequests(): array
    {
        return collect(Http::recorded())
            ->map(static fn (array $pair): ClientRequest => $pair[0])
            ->values()
            ->all();
    }

    public function lastSentRequest(): ClientRequest
    {
        $sent = $this->sentRequests();

        if ($sent === []) {
            throw new RuntimeException('Nothing has been sent yet.');
        }

        return $sent[count($sent) - 1];
    }

    /**
     * Re-point the `documents` disk at another backend, carrying every object over.
     *
     * A storage migration, in other words, and it is copy-then-switch for the same reason a
     * real one is: the rows in the database name paths, not backends, so the new backend has
     * to hold the same keys before anything reads from it. Nothing is regenerated — the
     * retained originals are copied byte-for-byte, which is what `AGENTS.md` requires of them
     * and what makes a digest comparison across the two backends mean something.
     *
     * @param  array<string, mixed>  $config
     * @return int How many objects were carried over.
     */
    public function useDocumentsDisk(array $config): int
    {
        $from = Storage::disk('documents');
        $objects = [];

        foreach ($from->allFiles() as $path) {
            $objects[$path] = $from->get($path);
        }

        Storage::forgetDisk('documents');
        config(['filesystems.disks.documents' => $config]);

        $to = Storage::disk('documents');

        foreach ($objects as $path => $bytes) {
            $to->put($path, $bytes);
        }

        return count($objects);
    }

    /* ========================================================================= booting */

    /**
     * Put the application into the shape a deployment is in, with only external I/O faked.
     */
    private function bootApplicationUnderTest(): void
    {
        // One consent version, everywhere.
        //
        // A deployment sets `ESIGN_CONSENT_POLICY_VERSION` once and both of these read it:
        // `signing.*` is what the guest page declares the notice on disk to be, and
        // `templates.*` is what a newly published version snapshots. The signing fixtures
        // carry their own distinct-looking string — deliberately, to prove the envelope's copy
        // is a snapshot rather than a live read — so a suite that publishes those templates
        // *and* walks a person through the guest pages has to make the deployment agree with
        // them, or every acceptance is refused for a mismatch that no real installation has
        // (`docs/security/review-2026-09.md` finding S-3). A published version is immutable,
        // so the configuration is what moves.
        config()->set('esign.signing.consent_policy_version', SigningFixtures::CONSENT_VERSION);
        config()->set('esign.templates.default_consent_policy_version', SigningFixtures::CONSENT_VERSION);

        // The facade scenario builds the workspace, the sender, the document and its review
        // revision with real fixture bytes behind them, and configures the fixture seal
        // material at B-B with no timestamp authority.
        $this->scenario = FirmaFacadeScenario::create();

        // It also swaps the event sink and the assurance check for doubles, which is right
        // for a state-machine test and wrong here: this suite exists to prove the *real*
        // delivery sink writes the outbox, and the *real* assurance check reads the seal
        // material it was given. Forgetting the instances puts the providers' own bindings
        // back on the path.
        app()->forgetInstance(EnvelopeEventSink::class);
        app()->forgetInstance(AssurancePolicyCheck::class);

        // Nothing may reach a timestamp authority. See RefusingTimestampAuthority for why a
        // TSA that answered would be dishonest rather than convenient.
        app()->instance(TimestampAuthority::class, new RefusingTimestampAuthority);

        // The committed fixture's real page geometry, which the guest signing page and the
        // facade's percent conversion both read. `DocumentFactory` makes rows without it.
        $this->describePagesFrom(FirmaFacadeScenario::DOCUMENT_FIXTURE);

        // Only the delivery job. Everything else stays on the synchronous queue.
        Queue::fake([DeliverWebhook::class]);
    }

    private function bootConsumer(): void
    {
        $this->credential = $this->scenario->credential();

        [$endpoint, $secret] = app(WebhookEndpointManager::class)->create(
            $this->scenario->workspace,
            self::WEBHOOK_URL,
            'Synthetic consumer inbox',
        );

        $this->endpoint = $endpoint;
        $this->receiver = new ConsumerWebhookReceiver([$secret]);

        $receiver = $this->receiver;
        Route::post(self::WEBHOOK_PATH, static fn (Request $request) => $receiver->receive($request));

        // The fence. One fake, matching this consumer's endpoint and nothing else; every
        // other destination has no matching fake and raises.
        Http::preventStrayRequests();
        Http::fake(fn (ClientRequest $request) => str_starts_with($request->url(), self::WEBHOOK_URL)
            ? $this->deliverToReceiver($request)
            : null);
    }

    /**
     * Hand one delivery to the consumer's receiver through the real HTTP kernel.
     */
    private function deliverToReceiver(ClientRequest $request): PromiseInterface
    {
        $behaviour = array_shift($this->transportBehaviours);

        if ($behaviour === self::DROP_REQUEST) {
            throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds.');
        }

        $response = $this->test->call(
            'POST',
            self::WEBHOOK_PATH,
            [],
            [],
            [],
            self::serverVars($request->headers()),
            $request->body(),
        );

        if ($behaviour === self::DROP_RESPONSE) {
            // The receiver has already processed it. This is why the event id is stable and
            // deduplication is the receiver's job.
            throw new ConnectionException('cURL error 52: Empty reply from server.');
        }

        return Http::response($response->getContent(), $response->getStatusCode());
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return array<string, string>
     */
    private static function serverVars(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $values) {
            $value = implode(', ', $values);
            $key = strtoupper(str_replace('-', '_', $name));

            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    /**
     * Record the fixture's real page geometry on the document row.
     *
     * Taken from `tests/Fixtures/pdf/manifest.json`, which is generated from the fixtures
     * themselves, so the pages described here are the pages in the PDF the signer is shown.
     */
    private function describePagesFrom(string $fixture): void
    {
        $pages = [];

        foreach (PdfFixtures::byName()[$fixture]['pages'] as $page) {
            $pages[] = [
                'page' => $page['page'],
                'crop_box' => array_map(static fn ($n): float => (float) $n, $page['crop_box']),
                'rotation' => $page['rotation'],
                'user_unit' => (float) $page['user_unit'],
                'native_width' => (float) $page['native_width'],
                'native_height' => (float) $page['native_height'],
            ];
        }

        $this->scenario->signing->document->forceFill([
            'page_count' => count($pages),
            'preflight_report' => ['accepted' => true, 'findings' => [], 'metrics' => [], 'pages' => $pages],
        ])->save();
    }
}
