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
 * as this process and honours `-d memory_limit`. The version check is not caution for its own sake.
 * On shared hosting the first `php` on the path is routinely an older installation than the one
 * serving the site, and a child running it would fail Composer's platform check on every read.
 *
 * Resolved lazily and remembered, so a web worker pays for the trial child once, on its first read.
 */
final class DocumentIsolation
{
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
     * What `esign:doctor` reports: the configured mode, the mode reads will actually get, and why.
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
                '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION."|".ini_get("memory_limit");',
            ]);
        } catch (Throwable $failed) {
            return 'a trial child from '.$candidate.' could not be started: '.$failed->getMessage();
        }

        if ($trial->exitCode() !== 0) {
            return 'a trial child from '.$candidate.' exited with status '.$trial->exitCode().'.';
        }

        [$version, $memoryLimit] = array_pad(explode('|', trim($trial->output()), 2), 2, '');

        if ($version !== $expected) {
            return $candidate.' runs PHP '.($version === '' ? 'of an unknown version' : $version)
                .', and this application runs PHP '.$expected.'; set ESIGN_DOCUMENTS_ISOLATION_PHP_BINARY to a '
                .$expected.' CLI.';
        }

        if ($memoryLimit !== '64M') {
            return 'a trial child from '.$candidate.' did not honour -d memory_limit (it reported '
                .($memoryLimit === '' ? 'nothing' : $memoryLimit).'), so it cannot be given a hard memory limit.';
        }

        $this->binary = $candidate;

        return null;
    }
}
