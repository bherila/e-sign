<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation\Isolation;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\Doctor\DocumentIsolationProbe;
use App\Domain\Delivery\Health\ReadinessChecker;
use App\Domain\Preparation\Isolation\DocumentIsolation;
use App\Domain\Preparation\Isolation\IsolationMode;
use Illuminate\Process\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `esign:doctor` reports the isolation a read will actually get, not the one configured.
 *
 * Three answers, one per outcome an operator has to act on differently: reads are bounded by a
 * child (nothing to do), reads fell back to in-process (the weaker bound, stated in
 * docs/assurance.md), and isolation is required but unavailable (nothing can be read at all).
 */
final class DocumentIsolationProbeTest extends TestCase
{
    /**
     * @return iterable<string, array{IsolationMode, string, HealthStatus, string}>
     */
    public static function outcomes(): iterable
    {
        yield 'a trusted child is available' => [IsolationMode::Auto, PHP_BINARY, HealthStatus::Ok, 'child process'];
        yield 'configured to read in-process' => [IsolationMode::InProcess, PHP_BINARY, HealthStatus::Warn, 'in-process'];
        yield 'auto, with no child it can trust' => [IsolationMode::Auto, '/definitely/not/a/php/binary', HealthStatus::Warn, 'not an executable file'];
        yield 'required, with no child it can trust' => [IsolationMode::Process, '/definitely/not/a/php/binary', HealthStatus::Fail, 'cannot be read'];
    }

    #[DataProvider('outcomes')]
    public function test_the_probe_reports_what_reads_will_actually_get(
        IsolationMode $mode,
        string $binary,
        HealthStatus $expected,
        string $says,
    ): void {
        $result = (new DocumentIsolationProbe(new DocumentIsolation($mode, app(Factory::class), $binary, 0, 0.0)))->check();

        $this->assertSame('document_isolation', $result->name);
        $this->assertSame($expected, $result->status);
        $this->assertStringContainsString($says, $result->message);
        $this->assertStringContainsString('from the command line', $result->message, 'The CLI probe does not say it speaks for CLI reads only.');
    }

    /**
     * The CLI's answer does not hold for the web handler, which reads every upload, so readiness —
     * served by the web handler — runs the probe there.
     */
    public function test_readiness_reports_isolation_as_the_web_handler_sees_it(): void
    {
        $probes = app(ReadinessChecker::class)->run()->toArray()['probes'];

        $this->assertArrayHasKey('document_isolation', $probes);
    }
}
