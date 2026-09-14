<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Isolation;

use App\Domain\Preparation\Assembly\AssembledDocument;
use App\Domain\Preparation\Assembly\OverlayRectangle;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Isolation\DocumentReadCodec;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\Preflight\PreflightReport;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfPreflight;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\TextRun;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfFixtures;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Every value a child read returns survives the process boundary intact, and nothing else crosses it.
 *
 * The allowlists are the security boundary between a process that parses hostile bytes and the one
 * that trusts its answer, so they are tested in both directions: a real value of every response type
 * round-trips equal, and a class outside the list is refused rather than rebuilt.
 */
final class DocumentReadCodecTest extends TestCase
{
    /**
     * A real value of every type the child can return, produced by the same adapters it runs.
     *
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function responses(): iterable
    {
        yield 'a preflight report' => [static fn (): PreflightReport => (new TcPdfPreflight)->inspect(PdfFixtures::bytes('rotated-pages'))];
        yield 'a rejected preflight report' => [static fn (): PreflightReport => (new TcPdfPreflight)->inspect('not a pdf')];
        yield 'text runs' => [static fn (): array => (new TcPdfTextLocator)->extract(PdfFixtures::bytes('multi-page-mixed-size'))];
        yield 'an assembled document' => [static fn (): AssembledDocument => (new TcPdfAssembler(null, resource_path('fonts'), new PreflightLimits))
            ->assemble(PdfFixtures::bytes('single-page-letter'), [new OverlayRectangle(1, new NativeRect(10.0, 10.0, 50.0, 20.0))])];
    }

    #[DataProvider('responses')]
    public function test_every_response_type_round_trips_equal(\Closure $produce): void
    {
        $value = $produce();

        $decoded = DocumentReadCodec::decode(
            DocumentReadCodec::encode(['ok' => true, 'value' => $value]),
            DocumentReadCodec::RESPONSE_CLASSES,
        );

        $this->assertEquals($value, $decoded['value']);

        if (is_array($value)) {
            $this->assertContainsOnlyInstancesOf(TextRun::class, $decoded['value']);
        }
    }

    public function test_a_request_carrying_limits_and_overlays_round_trips_equal(): void
    {
        $request = [
            'operation' => 'assemble',
            'limits' => new PreflightLimits(maxPages: 7, timeBudgetSeconds: 2.5),
            'overlays' => [new OverlayRectangle(1, new NativeRect(1.0, 2.0, 3.0, 4.0))],
            'bytes' => "%PDF-1.7\n",
        ];

        $this->assertEquals($request, DocumentReadCodec::decode(DocumentReadCodec::encode($request), DocumentReadCodec::REQUEST_CLASSES));
    }

    /**
     * A class outside the list is refused, including one buried inside an allowed value.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function smuggled(): iterable
    {
        yield 'at the top of the message' => [new \ArrayObject(['x'])];
        yield 'inside a list' => [[new \SplStack]];
        yield 'a request class in a response' => [new PreflightLimits];
    }

    #[DataProvider('smuggled')]
    public function test_a_class_outside_the_allowlist_is_refused(mixed $value): void
    {
        $this->expectException(UnexpectedValueException::class);

        DocumentReadCodec::decode(
            DocumentReadCodec::encode(['ok' => true, 'value' => $value]),
            DocumentReadCodec::RESPONSE_CLASSES,
        );
    }

    public function test_a_payload_that_is_not_a_message_is_refused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        DocumentReadCodec::decode('this is not serialized', DocumentReadCodec::RESPONSE_CLASSES);
    }
}
