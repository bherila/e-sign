<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes\Doctor;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;
use App\Domain\Preparation\Isolation\DocumentIsolation;
use App\Domain\Preparation\Isolation\IsolationMode;

/**
 * Whether document reads will run in a bounded child process, and if not, why not.
 *
 * Reports what reads will actually get, not what is configured: the resolution starts a trial
 * child, checks it runs this application's PHP version and honours `-d memory_limit`, and only
 * then counts it (docs/adr/0006). Run from the CLI, this sees the CLI's view — a web worker
 * resolves the same way on its first read, and `ESIGN_DOCUMENTS_ISOLATION_PHP_BINARY` is what
 * makes the two agree on shared hosting, where the web handler's `PHP_BINARY` is not a CLI.
 */
final readonly class DocumentIsolationProbe implements HealthProbe
{
    public function __construct(private DocumentIsolation $isolation) {}

    public function name(): string
    {
        return 'document_isolation';
    }

    public function check(): ProbeResult
    {
        $state = $this->isolation->describe();

        if ($state['effective'] === IsolationMode::Process) {
            return ProbeResult::ok($this->name(), sprintf(
                'Document reads run in a child process (%s) with hard memory and time limits.',
                $state['binary'] ?? 'PHP CLI',
            ));
        }

        if ($state['mode'] === IsolationMode::Process) {
            return ProbeResult::fail(
                $this->name(),
                'Process isolation is required but unavailable, so documents cannot be read: '.$state['reason'],
            );
        }

        return ProbeResult::warn(
            $this->name(),
            'Document reads run in-process, bounded by the cooperative budget only: '.$state['reason'],
        );
    }
}
