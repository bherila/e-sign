<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Domain\Integration\Native\ApiException;
use App\Domain\Integration\Native\Cursor;
use App\Http\Requests\Templates\WorkspaceTemplateRequest;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every anchored regular expression under `app/` means "the whole subject", not "the whole
 * subject, or all of it but a final newline" (issue #135).
 *
 * In PCRE, `$` also matches immediately before a trailing `\n` unless the pattern carries the
 * `D` (dollar-end-only) modifier. A guard written `/^[0-9a-f]{64}$/` therefore passes a digest
 * with a newline appended, and whatever the guard protects receives a value it was written to
 * refuse. The same defect was fixed in the field-schema importer in #134, which is where the
 * class was first noticed.
 *
 * This test reads source text, so it needs no application. It finds every string literal that
 * ends a pattern with `$`, its delimiter and its modifiers, and fails when the modifiers lack
 * `D`. `\z` is the other correct spelling and is not matched by the scan.
 */
final class AnchoredPatternTest extends TestCase
{
    /**
     * Patterns that are deliberately spelled without `D`, keyed by file and constant.
     *
     * The field-schema contract constants keep the contract's exact ECMA-262 spelling, which
     * `FieldSchemaContractTest` asserts, and are only ever matched through the hand-checked PCRE
     * translations in `FieldSchemaValidator::PCRE`, each of which does carry `D`.
     */
    private const SPELLED_AS_THE_CONTRACT = [
        'app/Domain/Preparation/Schema/FieldSchemaValidator.php' => [
            'IDENTIFIER_PATTERN',
            'VARIABLE_PATTERN',
            'EMAIL_PATTERN',
            'DOCUMENT_SHA256_PATTERN',
        ],
    ];

    /** A pattern literal ending in `$`, its delimiter and its modifiers, in either quote style. */
    private const SCAN = '/\$([\/#~@!|%}])([A-Za-z]*)[\'"]/';

    /** Where a guard can live: the application, and the test harness's own safety checks. */
    private const SCANNED = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests/Support'];

    public function test_every_anchored_pattern_in_the_application_is_dollar_end_only(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        foreach (self::SCANNED as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);
                $lines = file($file->getPathname()) ?: [];

                foreach ($lines as $number => $line) {
                    if (preg_match_all(self::SCAN, $line, $matches, PREG_SET_ORDER) === 0) {
                        continue;
                    }

                    foreach ($matches as $match) {
                        // `m` makes `$` match before *every* newline, and `D` is ignored under it.
                        $dollarEndOnly = str_contains($match[2], 'D') && ! str_contains($match[2], 'm');

                        if ($dollarEndOnly || $this->spelledAsTheContract($relative, $line)) {
                            continue;
                        }

                        $offenders[] = $relative.':'.($number + 1).': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These patterns end in `$` without the `D` modifier, so they also accept the value with a trailing '
                ."newline. Add `D` (or anchor with `\\z`).\n".implode("\n", $offenders),
        );
    }

    /**
     * The exemption above is only sound while the contract constants reach PCRE through their
     * translations. Passing one straight to `preg_match()` would match with PCRE's own meaning.
     */
    public function test_the_contract_pattern_constants_are_never_handed_to_pcre_directly(): void
    {
        $root = dirname(__DIR__, 3);
        $names = implode('|', self::SPELLED_AS_THE_CONTRACT['app/Domain/Preparation/Schema/FieldSchemaValidator.php']);
        $direct = '/preg_[a-z_]+\(\s*(?:self|static|FieldSchemaValidator)::(?:'.$names.')\b/D';
        $offenders = [];

        foreach (self::SCANNED as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && preg_match($direct, (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Match contract patterns through FieldSchemaValidator::matchesContractPattern().');
    }

    public function test_the_scan_recognises_a_pattern_without_the_modifier(): void
    {
        // Proves the scan can fail: the same expression the test applies, on a known offender.
        $this->assertSame(1, preg_match(self::SCAN, "preg_match('/^[a-z]+\$/', \$value)"));
        $this->assertSame(1, preg_match(self::SCAN, "'regex:/^[0-9a-f]{64}\$/'"));
        $this->assertSame(1, preg_match(self::SCAN, '"/^[a-z]+$/i"'));
        $this->assertSame(1, preg_match(self::SCAN, "'@^[0-9]+\$@'"));
        $this->assertSame(1, preg_match(self::SCAN, "'{^[0-9]+\$}'"));
        $this->assertSame(1, preg_match(self::SCAN, "'/^[a-z]+\$/iD'", $match));
        $this->assertStringContainsString('D', $match[2], 'A pattern that carries D is recognised as carrying it.');
    }

    public function test_a_cursor_with_a_trailing_newline_is_not_one_this_api_issued(): void
    {
        $forged = rtrim(strtr(base64_encode("v1:5\n"), '+/', '-_'), '=');

        $this->expectException(ApiException::class);

        Cursor::decode($forged);
    }

    public function test_a_template_version_number_with_a_trailing_newline_is_not_a_version_number(): void
    {
        $this->assertSame(1, preg_match(WorkspaceTemplateRequest::VERSION_NUMBER_PATTERN, '5'));
        $this->assertSame(0, preg_match(WorkspaceTemplateRequest::VERSION_NUMBER_PATTERN, "5\n"));
    }

    private function spelledAsTheContract(string $relative, string $line): bool
    {
        foreach (self::SPELLED_AS_THE_CONTRACT[$relative] ?? [] as $constant) {
            if (str_contains($line, 'const '.$constant.' =')) {
                return true;
            }
        }

        return false;
    }
}
