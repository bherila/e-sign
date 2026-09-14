<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use RuntimeException;

/**
 * The child read failed in a way that is neither a named ceiling nor a statement about the
 * document: it exited unexpectedly, or answered with something that could not be decoded.
 *
 * Each port adapter turns this into the failure its own contract promises for a document it could
 * not read; it never leaves the isolation layer as itself.
 */
final class ChildReadFailed extends RuntimeException {}
