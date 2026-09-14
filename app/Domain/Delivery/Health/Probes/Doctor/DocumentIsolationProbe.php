<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes\Doctor;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;
use App\Domain\Preparation\Isolation\DocumentIsolation;
use App\Domain\Preparation\Isolation\IsolationMode;

/**
 * Whether document reads *in this SAPI* will run in a bounded child process, and if not, why not.
 *
 * Reports what reads will actually get, not what is configured: the resolution starts a trial
 * child and checks its PHP version, its `-d memory_limit` and its extensions before counting it
 * (docs/adr/0006). That answer holds only for the SAPI the probe runs in. The CLI and the web
 * handler can have different ini files — one may disable `proc_open` while the other allows it —
 * and uploads are read in the web request while finalization is read on the queue. So the probe is
 * registered twice: in `esign:doctor`, where it speaks for the queue, the scheduler and artisan, and
 * in `/health/ready`, where it speaks for web requests. The message says which it is.
 *
 * Each run of the web probe starts one trial child, the same cost a request pays on its first read.
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
        $reads = PHP_SAPI === 'cli'
            ? 'Document reads from the command line (queue, scheduler, artisan)'
            : 'Document reads in web requests';

        if ($state['effective'] === IsolationMode::Process) {
            return ProbeResult::ok($this->name(), sprintf(
                '%s run in a child process (%s) with hard memory and time limits.',
                $reads,
                $state['binary'] ?? 'PHP CLI',
            ));
        }

        if ($state['mode'] === IsolationMode::Process) {
            return ProbeResult::fail(
                $this->name(),
                $reads.' require process isolation, which is unavailable, so documents cannot be read: '.$state['reason'],
            );
        }

        return ProbeResult::warn(
            $this->name(),
            $reads.' run in-process, bounded by the cooperative budget only: '.$state['reason'],
        );
    }
}
