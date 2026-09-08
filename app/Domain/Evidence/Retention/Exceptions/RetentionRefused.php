<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Exceptions;

use RuntimeException;

/**
 * A retention or restore operation refused to proceed because its preconditions were not
 * met — a policy that is not configured, a manifest that is not there, a drill guard that
 * is not set.
 *
 * Separate from {@see LegalHoldActive} because the two mean different things to an
 * operator: a hold is a decision somebody made about this envelope, and this is the tooling
 * saying it has not been told enough to act safely.
 */
final class RetentionRefused extends RuntimeException {}
