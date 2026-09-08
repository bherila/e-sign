<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation;

use PHPUnit\Framework\TestCase;

/**
 * docs/BLOB_STORAGE.md rule 1, enforced rather than remembered.
 *
 * A presigned URL is a bearer token: whoever holds it has the document until it expires,
 * with no session check and no workspace policy. It is also silently driver-specific — the
 * `local` driver does not implement `temporaryUrl()` and throws — so a presigning bug works
 * on the S3 deployment and 500s on the cPanel one, at runtime, on every file.
 *
 * This test scans the document module's *code* for the calls that would do it — comments
 * are stripped first, so a docblock may explain the rule without tripping it. It is
 * deliberately a source scan rather than a behavioural test: the point is that nobody adds
 * one later, in a branch no test happens to cover.
 */
class DocumentsAreNeverPresignedTest extends TestCase
{
    /** @var array<int, string> */
    private const FORBIDDEN = [
        'temporaryUrl',
        'temporaryUploadUrl',
        'Storage::url',
        'storage_path',
        'public_path',
        'asset(',
    ];

    /** @var array<int, string> */
    private const DIRECTORIES = [
        'app/Domain/Preparation/Documents',
        'app/Http/Controllers/Documents',
        'app/Http/Requests/Documents',
        'app/Http/Resources/Documents',
    ];

    public function test_no_document_code_can_mint_a_url_for_stored_bytes(): void
    {
        $offences = [];

        foreach ($this->sources() as $path => $contents) {
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($contents, $needle)) {
                    $offences[] = $path.' uses '.$needle;
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", $offences));
    }

    public function test_the_download_controller_streams_through_a_read_stream(): void
    {
        $controller = (string) file_get_contents(
            $this->basePath().'/app/Http/Controllers/Documents/DocumentRevisionDownloadController.php',
        );

        $this->assertStringContainsString('readStream', $controller);
    }

    public function test_the_documents_disk_is_private_and_is_not_served_by_the_framework(): void
    {
        $config = (string) file_get_contents($this->basePath().'/config/filesystems.php');

        $this->assertStringContainsString("'documents' => \$documentsDriver === 's3'", $config);
        // Both branches of the disk definition, and no public visibility anywhere in them.
        $this->assertSame(2, substr_count($config, "'visibility' => 'private'"));
        $this->assertStringContainsString("'serve' => false", $config);
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        $sources = [];

        foreach (self::DIRECTORIES as $directory) {
            $absolute = $this->basePath().'/'.$directory;
            $this->assertDirectoryExists($absolute);

            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[$directory.'/'.$file->getFilename()] = self::codeWithoutComments(
                        (string) file_get_contents($file->getPathname()),
                    );
                }
            }
        }

        $this->assertNotSame([], $sources);

        return $sources;
    }

    /**
     * The source with every comment and docblock removed, so the scan sees only what the
     * file actually executes.
     */
    private static function codeWithoutComments(string $php): string
    {
        $code = '';

        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }
}
