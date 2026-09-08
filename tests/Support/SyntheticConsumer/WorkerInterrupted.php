<?php

declare(strict_types=1);

namespace Tests\Support\SyntheticConsumer;

use RuntimeException;

/**
 * What {@see ObservingEnvelopeEventSink} throws to stand in for a killed worker.
 *
 * Its own type so a test can catch exactly the interruption it arranged, and not accidentally
 * swallow a genuine finalization failure that happened to reach the same catch block.
 */
final class WorkerInterrupted extends RuntimeException {}
