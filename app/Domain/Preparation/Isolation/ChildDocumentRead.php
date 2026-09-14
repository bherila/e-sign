<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Assembly\UnsupportedSourceException;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfPreflight;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\TextExtractionException;
use Throwable;
use UnexpectedValueException;

/**
 * The child side of a process-bounded document read (docs/adr/0006).
 *
 * Runs the same tc-lib-pdf adapters an in-process read runs, on the limits the parent sends, and
 * answers with a message rather than an exception: nothing thrown here can cross the process
 * boundary, so every outcome — a value, a named ceiling, an unreadable document — is written down
 * as data and rebuilt on the other side into what the port promises.
 *
 * Deliberately free of the framework. The entrypoint loads Composer's autoloader and calls
 * {@see main()}; no container, configuration or database is booted, which is what keeps a child
 * start in tens of milliseconds rather than the hundreds a full application boot costs.
 */
final class ChildDocumentRead
{
    public const PREFLIGHT = 'preflight';

    public const EXTRACT = 'extract';

    public const ASSEMBLE = 'assemble';

    /** The exit status of a child its own `memory_limit` stopped. Arbitrary, but not one PHP uses. */
    public const MEMORY_EXHAUSTED = 86;

    /**
     * Exit with {@see MEMORY_EXHAUSTED} when this process dies of memory exhaustion.
     *
     * The parent must tell that death apart from every other one, and the fatal error's text is
     * not a reliable witness: whether it is printed at all depends on the child binary's
     * `error_reporting`, which `php.ini` or the code may have turned off. The last error is
     * recorded regardless, and a shutdown function still runs after the fatal — with a little
     * memory released first, so the check itself has room to run.
     */
    public static function exitWhenMemoryIsExhausted(): void
    {
        $reserve = str_repeat("\0", 262_144);

        register_shutdown_function(static function () use (&$reserve): void {
            $reserve = null;
            $error = error_get_last();

            if ($error !== null && $error['type'] === E_ERROR && str_starts_with($error['message'], 'Allowed memory size of')) {
                exit(self::MEMORY_EXHAUSTED);
            }
        });
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public static function main($in, $out): int
    {
        try {
            $response = self::handle(DocumentReadCodec::decode(
                (string) stream_get_contents($in),
                DocumentReadCodec::REQUEST_CLASSES,
            ));
        } catch (Throwable $unexpected) {
            $response = [
                'ok' => false,
                'kind' => 'failed',
                'code' => null,
                'message' => $unexpected::class.': '.$unexpected->getMessage(),
            ];
        }

        fwrite($out, DocumentReadCodec::encode($response));

        return 0;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public static function handle(array $request): array
    {
        $limits = $request['limits'] ?? null;
        $bytes = $request['bytes'] ?? null;

        if (! $limits instanceof PreflightLimits || ! is_string($bytes)) {
            throw new UnexpectedValueException('A document read request needs limits and bytes.');
        }

        // A budget in the child only when the parent's caller owns one, so the rule about who is
        // told of a ceiling is decided by the same fact on both sides of the boundary.
        $budget = ($request['charged'] ?? false) === true ? new PreflightBudget($limits) : null;

        // What the read cost, on every answer and not only a successful one: a read that fails after
        // doing work — an encrypted document is parsed before it is refused — has still spent it, and
        // the in-process path leaves that charged to the caller's budget.
        $spent = static fn (): array => [
            'decoded' => $budget?->decodedBytes() ?? 0,
            'objects' => $budget?->objectCount() ?? 0,
        ];

        try {
            $value = match ($request['operation'] ?? null) {
                self::PREFLIGHT => (new TcPdfPreflight($limits))->inspect($bytes),
                self::EXTRACT => (new TcPdfTextLocator($limits))->extract(
                    $bytes,
                    is_int($request['page'] ?? null) ? $request['page'] : null,
                    $budget,
                ),
                self::ASSEMBLE => (new TcPdfAssembler(
                    new TcPdfPreflight($limits),
                    is_string($request['fonts'] ?? null) ? $request['fonts'] : null,
                    $limits,
                ))->assemble(
                    $bytes,
                    is_array($request['overlays'] ?? null) ? $request['overlays'] : [],
                    is_array($request['appended'] ?? null) ? $request['appended'] : [],
                ),
                default => throw new UnexpectedValueException('Unknown document read operation.'),
            };

            return ['ok' => true, 'value' => $value] + $spent();
        } catch (PreflightBudgetException $exhausted) {
            return self::ceiling('budget', $exhausted, $exhausted->getMessage()) + $spent();
        } catch (TextExtractionException $unreadable) {
            return ['ok' => false, 'kind' => 'unreadable', 'code' => null, 'message' => $unreadable->getMessage()] + $spent();
        } catch (UnsupportedSourceException $unsupported) {
            return ['ok' => false, 'kind' => 'unsupported', 'code' => null, 'message' => $unsupported->getMessage()] + $spent();
        } catch (AssemblyException $failed) {
            $ceiling = $failed->getPrevious();

            return ($ceiling instanceof PreflightBudgetException
                ? self::ceiling('assembly', $ceiling, $failed->getMessage())
                : ['ok' => false, 'kind' => 'assembly', 'code' => null, 'message' => $failed->getMessage()]) + $spent();
        }
    }

    /** @return array<string, mixed> */
    private static function ceiling(string $kind, PreflightBudgetException $exhausted, string $message): array
    {
        return [
            'ok' => false,
            'kind' => $kind,
            'code' => $exhausted->preflightCode->value,
            'per_stream' => $exhausted->perStream,
            'message' => $message,
        ];
    }
}
