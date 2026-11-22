<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Whether provider identity enforcement is on is answered by the package's
 * ProviderIdentityEnforcement, which a recorded cutover keeps on whatever the setting says.
 * Application code that read `bherila-auth.provider_identity.enabled` itself would leave its
 * path outside that lock: a configuration rollback could turn it off while every other path
 * stayed enforced. Comments count too, so the key is never written down as a recipe to copy
 * (docs/operations/provider-identity.md).
 */
final class ProviderIdentityEnforcementCensusTest extends TestCase
{
    public function test_application_code_never_reads_the_enforcement_setting_directly(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'provider_identity.enabled')) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        $this->assertSame([], $offenders, 'Ask app(BWH\Auth\OAuth\Session\ProviderIdentityEnforcement::class)->enabled() instead.');
    }
}
