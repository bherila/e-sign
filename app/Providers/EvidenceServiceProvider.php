<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Webhooks\TextRedactor;
use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\Artifacts\DiskArtifactStore;
use App\Domain\Evidence\Finalization\CompletionReportDocument;
use App\Domain\Evidence\Finalization\Console\PruneStagingArtifactsCommand;
use App\Domain\Evidence\Finalization\EnvelopeFinalizer;
use App\Domain\Evidence\Finalization\ExecutedDocumentRenderer;
use App\Domain\Evidence\Sealing\ConfiguredSealIdentity;
use App\Domain\Evidence\Sealing\Console\SealStatusCommand;
use App\Domain\Evidence\Sealing\HttpTimestampAuthority;
use App\Domain\Evidence\Sealing\SealMaterial;
use App\Domain\Evidence\Sealing\TcLibPdfArtifactValidator;
use App\Domain\Evidence\Sealing\TcLibPdfSealer;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Evidence module: the sealing ports to their tc-lib-pdf implementations, and the
 * staged artifact publication on top of them.
 *
 * The seal material is bound as a closure rather than an instance so an unconfigured or
 * broken key configuration fails when a seal, a preflight, or a status query is attempted,
 * with a typed exception naming the problem, instead of preventing the application from
 * booting at all.
 *
 * Artifacts share the `documents` disk. One private disk holds uploaded originals, review
 * revisions, and published evidence, distinguished by key prefix rather than by disk name, so
 * a deployment configures storage once (config/filesystems.php) and the prefixes keep the two
 * key spaces from colliding.
 */
final class EvidenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TimestampAuthority::class, function (Application $app): TimestampAuthority {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('esign.tsa', []);

            // The same outbound destination policy webhook delivery uses, so an
            // administrator allowlist entry for an internal host is configured in
            // exactly one place.
            return HttpTimestampAuthority::fromConfig($config, $app->make(DestinationPolicy::class));
        });

        $this->app->singleton(ArtifactValidator::class, TcLibPdfArtifactValidator::class);

        $this->app->singleton(PdfSealer::class, function (Application $app): PdfSealer {
            $configRepository = $app->make('config');

            return new TcLibPdfSealer(
                material: static function () use ($configRepository): SealMaterial {
                    /** @var array<string, mixed> $seal */
                    $seal = $configRepository->get('esign.seal', []);

                    return SealMaterial::fromConfig($seal);
                },
                timestampAuthority: $app->make(TimestampAuthority::class),
                validator: $app->make(ArtifactValidator::class),
                allowSha1TimestampToken: $configRepository->get('esign.tsa.allow_sha1_token') === true,
            );
        });

        // The public half of the same material. Bound separately from the sealer because
        // reading a certificate's expiry is not a privileged operation and must not require
        // being able to sign with it.
        $this->app->singleton(SealIdentity::class, function (Application $app): SealIdentity {
            $configRepository = $app->make('config');

            return new ConfiguredSealIdentity(
                material: static function () use ($configRepository): SealMaterial {
                    /** @var array<string, mixed> $seal */
                    $seal = $configRepository->get('esign.seal', []);

                    return SealMaterial::fromConfig($seal);
                },
                timestampAuthority: $app->make(TimestampAuthority::class),
            );
        });

        $this->app->bind(ArtifactStore::class, DiskArtifactStore::class);

        $this->app->bind(
            CompletionReportDocument::class,
            fn (Application $app): CompletionReportDocument => new CompletionReportDocument(
                $app->resourcePath('fonts'),
            ),
        );

        $this->app->bind(
            ExecutedDocumentRenderer::class,
            fn (Application $app): ExecutedDocumentRenderer => new ExecutedDocumentRenderer(
                $app->make(PdfAssembler::class),
            ),
        );

        $this->app->bind(EnvelopeFinalizer::class, function (Application $app): EnvelopeFinalizer {
            /** @var Repository $config */
            $config = $app->make('config');

            return new EnvelopeFinalizer(
                stateMachine: $app->make(EnvelopeStateMachine::class),
                sealer: $app->make(PdfSealer::class),
                validator: $app->make(ArtifactValidator::class),
                sealIdentity: $app->make(SealIdentity::class),
                renderer: $app->make(ExecutedDocumentRenderer::class),
                completionReport: $app->make(CompletionReportDocument::class),
                store: $app->make(ArtifactStore::class),
                documents: $app->make(DocumentBlobStore::class),
                redactor: $app->make(TextRedactor::class),
                disk: (string) $config->get('esign.documents.disk'),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SealStatusCommand::class,
                PruneStagingArtifactsCommand::class,
            ]);
        }
    }
}
