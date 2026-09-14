<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Domain\Integration\Firma\FirmaErrorCode;
use App\Domain\Integration\Firma\FirmaErrorMap;
use App\Domain\Integration\Native\ApiErrorMap;
use App\Domain\Integration\Native\ErrorCode;
use App\Domain\Preparation\Contracts\DocumentReadUnavailable;
use App\Domain\Preparation\Isolation\ChildReadFailed;
use App\Domain\Preparation\Isolation\DocumentIsolationUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A document read that failed on the service side, as each HTTP surface answers it (#119).
 *
 * Both answers say the deployment failed rather than the request, both are reported, and neither
 * carries the exception's own message: that can name the host's PHP binary.
 */
final class DocumentReadUnavailableMappingTest extends TestCase
{
    /** @return iterable<string, array{DocumentReadUnavailable}> */
    public static function failures(): iterable
    {
        yield 'a child that failed' => [
            DocumentReadUnavailable::childFailed(new ChildReadFailed('The child run from /opt/example/bin/php exited with status 255.')),
        ];
        yield 'isolation that is unavailable' => [
            new DocumentIsolationUnavailable('This host cannot provide one: /opt/example/bin/php is not an executable file.'),
        ];
    }

    #[DataProvider('failures')]
    public function test_the_native_api_answers_a_retryable_503_and_reports_it(DocumentReadUnavailable $failure): void
    {
        $error = ApiErrorMap::translate($failure);

        $this->assertSame(ErrorCode::DocumentUnavailable, $error->errorCode);
        $this->assertSame(503, $error->status());
        $this->assertSame(['retryable' => true], $error->details);
        $this->assertSame($failure->publicMessage(), $error->getMessage());
        $this->assertStringNotContainsString('/opt/example', (string) json_encode($error->toArray(), JSON_UNESCAPED_SLASHES));
        $this->assertFalse(ApiErrorMap::isExpectedRefusal($failure), 'A deployment fault is reported, even when it is answered plainly.');
    }

    #[DataProvider('failures')]
    public function test_the_firma_facade_answers_its_500_and_never_a_refused_body(DocumentReadUnavailable $failure): void
    {
        $error = FirmaErrorMap::translate($failure);

        $this->assertSame(FirmaErrorCode::InternalError, $error->errorCode);
        $this->assertSame($failure->publicMessage(), $error->getMessage());
        $this->assertStringNotContainsString('/opt/example', (string) json_encode($error->toArray(), JSON_UNESCAPED_SLASHES));
        $this->assertFalse(FirmaErrorMap::isExpectedRefusal($failure));
    }
}
