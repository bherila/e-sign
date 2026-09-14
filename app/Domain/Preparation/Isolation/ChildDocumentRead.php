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

            return [
                'ok' => true,
                'value' => $value,
                'decoded' => $budget?->decodedBytes() ?? 0,
                'objects' => $budget?->objectCount() ?? 0,
            ];
        } catch (PreflightBudgetException $exhausted) {
            return ['ok' => false, 'kind' => 'budget', 'code' => $exhausted->preflightCode->value, 'message' => $exhausted->getMessage()];
        } catch (TextExtractionException $unreadable) {
            return ['ok' => false, 'kind' => 'unreadable', 'code' => null, 'message' => $unreadable->getMessage()];
        } catch (UnsupportedSourceException $unsupported) {
            return ['ok' => false, 'kind' => 'unsupported', 'code' => null, 'message' => $unsupported->getMessage()];
        } catch (AssemblyException $failed) {
            $ceiling = $failed->getPrevious();

            return [
                'ok' => false,
                'kind' => 'assembly',
                'code' => $ceiling instanceof PreflightBudgetException ? $ceiling->preflightCode->value : null,
                'message' => $failed->getMessage(),
            ];
        }
    }
}
