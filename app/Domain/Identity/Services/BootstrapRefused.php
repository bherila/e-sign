<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use RuntimeException;

/**
 * A deliberate refusal by OwnerBootstrapper, addressed to the operator.
 *
 * This type exists so the console command can catch the refusals this domain
 * raises and nothing else. It used to catch `RuntimeException`, and
 * `Illuminate\Database\QueryException` extends `PDOException` extends
 * `RuntimeException` — so every SQL failure was presented as an operator
 * refusal, with the raw statement and its bindings printed as the problem and
 * whatever followed the first newline printed as the remedy. The real fault
 * was hidden and no stack trace was produced.
 *
 * The message carries the problem, then optionally a blank-line-free remedy
 * after a single newline, which is the shape the command prints.
 */
class BootstrapRefused extends RuntimeException {}
