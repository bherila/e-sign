<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\ReviewNormalizer;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfPreflight;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
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
            );
        });

        $this->app->bind(
            PdfPreflight::class,
            fn (Application $app): PdfPreflight => new TcPdfPreflight($app->make(PreflightLimits::class)),
        );

        // The assembler runs preflight again on its own input and refuses anything it
        // rejects, so an unsafe document cannot reach the importer by a different door.
        $this->app->bind(
            PdfAssembler::class,
            fn (Application $app): PdfAssembler => new TcPdfAssembler($app->make(PdfPreflight::class)),
        );

        $this->app->bind(PdfTextLocator::class, TcPdfTextLocator::class);

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
    }
}
