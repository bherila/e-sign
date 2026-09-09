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
use Illuminate\Support\ServiceProvider;

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

        $this->app->bind(
            PdfPreflight::class,
            fn (Application $app): PdfPreflight => new TcPdfPreflight($app->make(PreflightLimits::class)),
        );

        // The assembler runs preflight again on its own input and refuses anything it
        // rejects, so an unsafe document cannot reach the importer by a different door.
        //
        // The font directory is passed in rather than discovered, because the PDF engine
        // resolves it through a process-wide constant: naming it here keeps "where the
        // bundled text metrics live" a wiring decision with one answer per deployment.
        // See App\Domain\Preparation\TcPdf\CoreFontMetrics.
        $this->app->bind(
            PdfAssembler::class,
            fn (Application $app): PdfAssembler => new TcPdfAssembler(
                $app->make(PdfPreflight::class),
                $app->resourcePath('fonts'),
            ),
        );

        $this->app->bind(PdfTextLocator::class, TcPdfTextLocator::class);

        // How anchor resolution is assembled. Nothing in this build calls it yet — publishing and
        // sending wire it up in the pieces that follow — but a class whose collaborators are not
        // declared anywhere is one nobody can construct, and the cross-check tolerance is a
        // deployment setting rather than a constant, so it has to be read here.
        $this->app->bind(SchemaAnchorResolver::class, function (Application $app): SchemaAnchorResolver {
            /** @var Repository $config */
            $config = $app->make('config');

            return new SchemaAnchorResolver(
                $app->make(AnchorResolver::class),
                (float) $config->get(
                    'esign.preparation.anchor_cross_check_tolerance',
                    SchemaAnchorResolver::DEFAULT_CROSS_CHECK_TOLERANCE,
                ),
            );
        });

        $this->app->bind(
            RevisionAnchorResolver::class,
            fn (Application $app): RevisionAnchorResolver => new RevisionAnchorResolver(
                $app->make(PdfTextLocator::class),
                $app->make(RevisionBytes::class),
                $app->make(SchemaAnchorResolver::class),
                $app->make(PdfPreflight::class),
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
