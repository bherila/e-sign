<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Preparation\Anchoring\RevisionAnchorResolver;
use App\Domain\Preparation\Anchoring\SchemaAnchorResolver;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\ReviewNormalizer;
use App\Domain\Preparation\Isolation\DocumentIsolation;
use App\Domain\Preparation\Isolation\IsolatedPdfAssembler;
use App\Domain\Preparation\Isolation\IsolatedPdfPreflight;
use App\Domain\Preparation\Isolation\IsolatedPdfTextLocator;
use App\Domain\Preparation\Isolation\IsolationMode;
use App\Domain\Preparation\Documents\RevisionBytes;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\Schema\FieldSchemaValidator;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfPreflight;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Templates\TemplateService;
use App\Domain\Preparation\Text\AnchorResolver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

/**
 * Wires the Preparation module's ports to their tc-lib-pdf implementations and builds
 * document intake from `config('esign.documents')`.
 *
 * The limits are resolved here rather than read inside the services so that a deployment
 * that raises a ceiling changes one config file, and so that a test can lower one with
 * `config()->set()` and get a service that honours it.
 */
class PreparationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PreflightLimits::class, function (Application $app): PreflightLimits {
            /** @var Repository $config */
            $config = $app->make('config');

            return new PreflightLimits(
                maxBytes: (int) $config->get('esign.documents.max_bytes'),
                maxPages: (int) $config->get('esign.documents.max_pages'),
                maxObjects: (int) $config->get('esign.documents.max_objects'),
                maxDecodedStreamBytes: (int) $config->get('esign.documents.max_decoded_stream_bytes'),
                maxDecompressedBytes: (int) $config->get('esign.documents.max_decompressed_bytes'),
                timeBudgetSeconds: (float) $config->get('esign.documents.preflight_time_budget_seconds'),
                memoryBudgetBytes: (int) $config->get('esign.documents.preflight_memory_budget_bytes'),
            );
        });

        // Where document reads run (docs/adr/0006). A singleton, so a PHP process resolves its
        // mode — and starts its trial child — once, on the first read that needs it.
        $this->app->singleton(DocumentIsolation::class, function (Application $app): DocumentIsolation {
            /** @var Repository $config */
            $config = $app->make('config');

            return new DocumentIsolation(
                IsolationMode::fromSetting((string) $config->get('esign.documents.isolation.mode', 'auto')),
                $app->make(ProcessFactory::class),
                (string) $config->get('esign.documents.isolation.php_binary', ''),
                (int) $config->get('esign.documents.isolation.memory_headroom_bytes', 67_108_864),
                (float) $config->get('esign.documents.isolation.time_headroom_seconds', 5),
                $app->make(LoggerInterface::class),
            );
        });

        // Each port is the tc-lib-pdf adapter behind the isolation layer. The in-process adapters
        // are built directly, never resolved through these bindings, so an in-process assembly's
        // own preflight does not start a child of its own.
        $this->app->bind(PdfPreflight::class, function (Application $app): PdfPreflight {
            $limits = $app->make(PreflightLimits::class);

            return new IsolatedPdfPreflight(new TcPdfPreflight($limits), $app->make(DocumentIsolation::class), $limits);
        });

        // The assembler runs preflight again on its own input and refuses anything it
        // rejects, so an unsafe document cannot reach the importer by a different door.
        //
        // The font directory is passed in rather than discovered, because the PDF engine
        // resolves it through a process-wide constant: naming it here keeps "where the
        // bundled text metrics live" a wiring decision with one answer per deployment.
        // See App\Domain\Preparation\TcPdf\CoreFontMetrics.
        $this->app->bind(PdfAssembler::class, function (Application $app): PdfAssembler {
            $limits = $app->make(PreflightLimits::class);
            $fonts = $app->resourcePath('fonts');

            return new IsolatedPdfAssembler(
                new TcPdfAssembler(new TcPdfPreflight($limits), $fonts, $limits),
                $app->make(DocumentIsolation::class),
                $limits,
                $fonts,
            );
        });

        // Constructed with the configured limits rather than autowired bare, so a deployment
        // that raised a ceiling reads documents under the ceiling it set. Every site that reads
        // a document resolves its limits from this one binding; the enumeration in
        // tests/Feature/Preparation/DocumentReadBudgetTest.php is what keeps that true.
        $this->app->bind(PdfTextLocator::class, function (Application $app): PdfTextLocator {
            $limits = $app->make(PreflightLimits::class);

            return new IsolatedPdfTextLocator(new TcPdfTextLocator($limits), $app->make(DocumentIsolation::class), $limits);
        });

        // How anchor resolution is assembled. Nothing in this build calls it yet — publishing and
        // sending wire it up in the pieces that follow — but a class whose collaborators are not
        // declared anywhere is one nobody can construct, and the cross-check tolerance is a
        // deployment setting rather than a constant, so it has to be read here.
        $this->app->bind(SchemaAnchorResolver::class, function (Application $app): SchemaAnchorResolver {
            /** @var Repository $config */
            $config = $app->make('config');

            $tolerance = $config->get(
                'esign.preparation.anchor_cross_check_tolerance',
                SchemaAnchorResolver::DEFAULT_CROSS_CHECK_TOLERANCE,
            );

            // Parsed before it is cast. `(float) "one"` is `0.0` — a perfectly legal tolerance —
            // so casting first would turn a typo into a silent switch to demanding exact
            // coordinate matches, which is a change in what the product asserts rather than a
            // configuration error. The range is checked by the resolver's own constructor.
            if (! is_numeric($tolerance)) {
                throw new InvalidArgumentException(
                    'esign.preparation.anchor_cross_check_tolerance must be a number of points; got '
                        .var_export($tolerance, true).'. Set ESIGN_ANCHOR_CROSS_CHECK_TOLERANCE to a number, or '
                        .'leave it unset for the default.',
                );
            }

            return new SchemaAnchorResolver($app->make(AnchorResolver::class), (float) $tolerance);
        });

        $this->app->bind(
            RevisionAnchorResolver::class,
            fn (Application $app): RevisionAnchorResolver => new RevisionAnchorResolver(
                $app->make(PdfTextLocator::class),
                $app->make(RevisionBytes::class),
                $app->make(SchemaAnchorResolver::class),
                $app->make(PreflightLimits::class),
            ),
        );

        // Scoped: the memo of proven bytes must survive from one read to the next within a
        // request, and must not survive past it. See RevisionBytes.
        $this->app->scoped(RevisionBytes::class);

        $this->app->bind(ReviewNormalizer::class, function (Application $app): ReviewNormalizer {
            /** @var Repository $config */
            $config = $app->make('config');

            return new ReviewNormalizer(
                $app->make(PdfAssembler::class),
                (bool) $config->get('esign.documents.normalization.rebuild_pages'),
            );
        });

        $this->app->bind(DocumentIntake::class, function (Application $app): DocumentIntake {
            /** @var Repository $config */
            $config = $app->make('config');

            return new DocumentIntake(
                $app->make(PdfPreflight::class),
                $app->make(ReviewNormalizer::class),
                $app->make(DocumentBlobStore::class),
                $app->make(AuditRecorder::class),
                (string) $config->get('esign.documents.disk'),
            );
        });

        $this->app->bind(TemplateService::class, function (Application $app): TemplateService {
            /** @var Repository $config */
            $config = $app->make('config');

            return new TemplateService(
                $app->make(FieldSchemaValidator::class),
                $app->make(AuditRecorder::class),
                (string) $config->get('esign.templates.default_consent_policy_version'),
            );
        });
    }
}
