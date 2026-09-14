<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `Integration` consumes the domain; the domain never reaches back into it.
 *
 * The Firma facade and the native API translate shapes for callers that already exist. Their
 * vocabulary is a compatibility concern with a different lifetime from the domain's: a facade
 * can be retired, and the rules underneath it cannot. A domain module that imports one — even
 * only to point a docblock at it — makes the adapter the place a policy is defined, which is
 * the direction that produces "the state machine lives in a compatibility controller".
 *
 * Checked across every domain module rather than the one file that broke it. A rule with one
 * enforced instance is a rule the next file does not follow, and every module here has the same
 * reason to obey it.
 */
final class ModuleDirectionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function domainModules(): iterable
    {
        foreach (['Identity', 'Preparation', 'Signing', 'Evidence', 'Delivery'] as $module) {
            yield $module => [$module];
        }
    }

    #[DataProvider('domainModules')]
    public function test_a_domain_module_never_imports_the_integration_layer(string $module): void
    {
        $root = dirname(__DIR__, 3).'/app/Domain/'.$module;

        $this->assertDirectoryExists($root);

        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // The import, and the docblock reference that pulls one in behind it. `{@see X}`
            // resolves through the use statements, so a docblock is how this creeps back.
            if (preg_match('/^use App\\\\Domain\\\\Integration\\\\/m', $source) === 1) {
                $offenders[] = substr($file->getPathname(), strlen(dirname(__DIR__, 3)) + 1);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            $module.' must not import App\Domain\Integration. Move the shared policy into the domain module '
                .'that owns it, and let the adapter consume that instead.',
        );
    }
}
