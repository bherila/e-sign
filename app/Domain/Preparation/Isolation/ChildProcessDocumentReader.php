<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightLimits;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Throwable;
use UnexpectedValueException;

/**
 * Runs one document read in a child PHP process under hard limits.
 *
 * The two limits here do not depend on any code reporting its work. Memory is the child's own
 * `memory_limit`, which PHP enforces on every allocation, native or not. Time is a wall-clock
 * deadline after which the parent kills the child. Each is the configured backstop — once for every
 * read the operation budgets separately — plus headroom, so the cooperative budgets inside the child
 * still trip first and name the ceiling; these fire only for work nothing reported.
 */
final readonly class ChildProcessDocumentReader
{
    public function __construct(
        private Factory $processes,
        private string $phpBinary,
        private int $memoryHeadroomBytes,
        private float $timeHeadroomSeconds,
        private string $entrypoint = __DIR__.'/document-read.php',
    ) {}

    /**
     * @param  array<string, mixed>  $request  The operation and its arguments, without the limits.
     * @param  PreflightLimits  $limits  What the read may cost, and the base of the hard limits.
     * @param  int  $budgetedReads  How many reads the operation gives a fresh budget of `$limits`.
     *                              One for a preflight or an extraction; an assembly reads each of
     *                              its inputs more than once, each time on a budget of its own, and
     *                              a hard limit sized for one of them would stop legitimate work the
     *                              in-process path completes.
     * @return array<string, mixed> The child's response message.
     *
     * @throws PreflightBudgetException When the runtime stopped the read: its deadline or its memory.
     * @throws ChildReadFailed When the child failed in any other way, including not starting at all.
     */
    public function read(array $request, PreflightLimits $limits, int $budgetedReads = 1): array
    {
        $reads = max(1, $budgetedReads);
        $memoryBytes = $this->hardMemoryBytes($limits, $reads);
        $seconds = $this->hardSeconds($limits, $reads);

        try {
            $result = $this->processes
                ->timeout(max(1, (int) ceil($seconds)))
                ->input(DocumentReadCodec::encode(['limits' => $limits] + $request))
                ->run([
                    $this->phpBinary,
                    '-d', 'memory_limit='.$memoryBytes,
                    '-d', 'max_execution_time=0',
                    '-d', 'error_reporting=-1',
                    '-d', 'display_errors=stderr',
                    $this->entrypoint,
                ]);
        } catch (ProcessTimedOutException) {
            throw new PreflightBudgetException(
                PreflightCode::TimeBudgetExceeded,
                sprintf(
                    'Reading this PDF took longer than the %.0F-second limit for one document and was '
                    .'stopped. Split the document into smaller files, or re-export it from the application '
                    .'that produced it, and upload it again.',
                    ceil($seconds),
                ),
            );
        } catch (Throwable $unstarted) {
            // The binary was trusted when isolation was resolved, but a process can still fail to
            // start or be run (a transient proc_open failure, a binary removed since). That is the
            // child failing, which the ports already know how to report, not an unhandled error.
            throw new ChildReadFailed('The document read process could not be run: '.$unstarted->getMessage(), previous: $unstarted);
        }

        if ($result->exitCode() !== 0) {
            // The exit status is the witness; the fatal error's text is only a fallback, for a child
            // that ran out of memory before its entrypoint installed the handler that sets it.
            if (
                $result->exitCode() === ChildDocumentRead::MEMORY_EXHAUSTED
                || str_contains($result->errorOutput().$result->output(), 'Allowed memory size of')
            ) {
                throw new PreflightBudgetException(
                    PreflightCode::MemoryBudgetExceeded,
                    sprintf(
                        'Reading this PDF needed more than the %d bytes of memory allowed for one document '
                        .'and was stopped. Split the document into smaller files, or re-export it from the '
                        .'application that produced it, and upload it again.',
                        $memoryBytes,
                    ),
                );
            }

            throw new ChildReadFailed('The document read process exited with status '.$result->exitCode().'.');
        }

        try {
            return DocumentReadCodec::decode($result->output(), DocumentReadCodec::RESPONSE_CLASSES);
        } catch (UnexpectedValueException $undecodable) {
            throw new ChildReadFailed('The document read process answered with something that could not be decoded.', previous: $undecodable);
        }
    }

    /** The memory backstop, or the shipped one when the backstop is disabled, per read, plus headroom. */
    private function hardMemoryBytes(PreflightLimits $limits, int $reads): int
    {
        $base = $limits->memoryBudgetBytes > 0 ? $limits->memoryBudgetBytes : (new PreflightLimits)->memoryBudgetBytes;

        return $base * $reads + max(0, $this->memoryHeadroomBytes);
    }

    /** The time backstop, or the shipped one when the backstop is disabled, per read, plus headroom. */
    private function hardSeconds(PreflightLimits $limits, int $reads): float
    {
        $base = $limits->timeBudgetSeconds > 0.0 ? $limits->timeBudgetSeconds : (new PreflightLimits)->timeBudgetSeconds;

        return $base * $reads + max(0.0, $this->timeHeadroomSeconds);
    }
}
