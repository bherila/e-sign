<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use Illuminate\Process\Factory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Decides, once per PHP process, whether document reads run in a child process or in this one.
 *
 * A child is used only when one can actually be started *and trusted*: `proc_open` is not disabled,
 * a CLI binary is found, and a trial child started from it runs the same PHP major and minor version
 * as this process, honours `-d memory_limit`, and has every extension the PDF stack requires. None
 * of these is caution for its own sake. On shared hosting the first `php` on the path is routinely
 * an older installation than the one serving the site, and a configured binary can be a different
 * build with a different extension set; a child from either would fail every read as an unreadable
 * document rather than say what is wrong.
 *
 * The resolution answers for the SAPI it runs in, and the CLI and the web handler can differ in
 * exactly these respects, so `esign:doctor` and `/health/ready` each run the same
 * `document_isolation` probe where it applies.
 *
 * Resolved lazily and remembered, so a process pays for the trial child once, on its first read.
 */
final class DocumentIsolation
{
    /**
     * Every extension the tecnickcom packages declare in `composer.lock`, sorted. A test keeps the two
     * equal, so a dependency update that needs a new extension cannot silently trust a child without it.
     */
    public const REQUIRED_EXTENSIONS = ['ctype', 'curl', 'filter', 'gd', 'hash', 'json', 'mbstring', 'openssl', 'pcre', 'xml', 'zlib'];

    private bool $resolved = false;

    private ?ChildProcessDocumentReader $reader = null;

    private ?string $binary = null;

    private ?string $unavailable = null;

    public function __construct(
        private readonly IsolationMode $mode,
        private readonly Factory $processes,
        private readonly string $configuredBinary,
        private readonly int $memoryHeadroomBytes,
        private readonly float $timeHeadroomSeconds,
        private readonly ?LoggerInterface $log = null,
        private readonly string $entrypoint = __DIR__.'/document-read.php',
    ) {}

    /**
     * The child reader to use, or null to read in this process.
     *
     * @throws DocumentIsolationUnavailable When process isolation is required and cannot be provided.
     */
    public function reader(): ?ChildProcessDocumentReader
    {
        if ($this->mode === IsolationMode::InProcess) {
            return null;
        }

        $this->resolve();

        if ($this->reader instanceof ChildProcessDocumentReader) {
            return $this->reader;
        }

        if ($this->mode === IsolationMode::Process) {
            throw new DocumentIsolationUnavailable(
                'Document reads require a child process (ESIGN_DOCUMENTS_ISOLATION=process), and this host cannot '
                .'provide one: '.$this->unavailable,
            );
        }

        return null;
    }

    /**
     * What the `document_isolation` probe reports: the configured mode, the mode reads will actually get, and why.
     *
     * @return array{mode: IsolationMode, effective: IsolationMode, binary: ?string, reason: ?string}
     */
    public function describe(): array
    {
        if ($this->mode !== IsolationMode::InProcess) {
            $this->resolve();
        }

        return [
            'mode' => $this->mode,
            'effective' => $this->reader instanceof ChildProcessDocumentReader ? IsolationMode::Process : IsolationMode::InProcess,
            'binary' => $this->binary,
            'reason' => $this->mode === IsolationMode::InProcess
                ? 'ESIGN_DOCUMENTS_ISOLATION is in_process.'
                : $this->unavailable,
        ];
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;
        $this->unavailable = $this->whyUnavailable();

        if ($this->unavailable === null && $this->binary !== null) {
            $this->reader = new ChildProcessDocumentReader(
                $this->processes,
                $this->binary,
                $this->memoryHeadroomBytes,
                $this->timeHeadroomSeconds,
                $this->entrypoint,
            );

            return;
        }

        if ($this->mode === IsolationMode::Auto) {
            $this->log?->warning('Document reads fall back to in-process: '.$this->unavailable);
        }
    }

    /** Null when a trusted child can be started; otherwise a sentence saying why not. */
    private function whyUnavailable(): ?string
    {
        if (! function_exists('proc_open')) {
            return 'proc_open is not available in this PHP.';
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            return 'proc_open is listed in disable_functions.';
        }

        $configured = trim($this->configuredBinary);

        if ($configured !== '') {
            if (! is_file($configured) || ! is_executable($configured)) {
                return 'ESIGN_DOCUMENTS_ISOLATION_PHP_BINARY names '.$configured.', which is not an executable file.';
            }

            $candidate = $configured;
        } else {
            $found = (new PhpExecutableFinder)->find(false);

            if ($found === false) {
                return 'no PHP CLI binary was found; set ESIGN_DOCUMENTS_ISOLATION_PHP_BINARY.';
            }

            $candidate = $found;
        }

        $expected = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        try {
            $trial = $this->processes->timeout(15)->run([
                $candidate,
                '-d', 'memory_limit=64M',
                '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION."|".ini_get("memory_limit")."|"'
                    .'.implode(",", array_filter('.var_export(self::REQUIRED_EXTENSIONS, true).', fn ($e) => ! extension_loaded($e)));',
            ]);
        } catch (Throwable $failed) {
            return 'a trial child from '.$candidate.' could not be started: '.$failed->getMessage();
        }

        if ($trial->exitCode() !== 0) {
            return 'a trial child from '.$candidate.' exited with status '.$trial->exitCode().'.';
        }

        $answer = explode('|', trim($trial->output()), 3);
        [$version, $memoryLimit] = array_pad($answer, 2, '');

        if ($version !== $expected) {
            return $candidate.' runs PHP '.($version === '' ? 'of an unknown version' : $version)
                .', and this application runs PHP '.$expected.'; set ESIGN_DOCUMENTS_ISOLATION_PHP_BINARY to a '
                .$expected.' CLI.';
        }

        if ($memoryLimit !== '64M') {
            return 'a trial child from '.$candidate.' did not honour -d memory_limit (it reported '
                .($memoryLimit === '' ? 'nothing' : $memoryLimit).'), so it cannot be given a hard memory limit.';
        }

        if (count($answer) !== 3) {
            return 'a trial child from '.$candidate.' did not report its extensions, so it cannot be trusted to read documents.';
        }

        if ($answer[2] !== '') {
            return $candidate.' is missing required extension(s): '.str_replace(',', ', ', $answer[2])
                .'; set ESIGN_DOCUMENTS_ISOLATION_PHP_BINARY to a CLI that has them.';
        }

        $this->binary = $candidate;

        return null;
    }
}
