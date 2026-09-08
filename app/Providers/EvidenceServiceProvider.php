<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Sealing\HttpTimestampAuthority;
use App\Domain\Evidence\Sealing\SealMaterial;
use App\Domain\Evidence\Sealing\TcLibPdfArtifactValidator;
use App\Domain\Evidence\Sealing\TcLibPdfSealer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Evidence module's sealing ports to their tc-lib-pdf implementations.
 *
 * The seal material is bound as a closure rather than an instance so an
 * unconfigured or broken key configuration fails when a seal or a preflight is
 * attempted, with a typed exception naming the problem, instead of preventing
 * the application from booting at all.
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
    }
}
