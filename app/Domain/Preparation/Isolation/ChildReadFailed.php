<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use App\Domain\Preparation\Contracts\DocumentReadUnavailable;
use RuntimeException;

/**
 * The child read failed in a way that is neither a named ceiling nor a statement about the
 * document: it exited unexpectedly, or answered with something that could not be decoded.
 *
 * Each port adapter turns this into {@see DocumentReadUnavailable},
 * the service-side failure every document-read port declares; it never leaves the isolation layer as
 * itself, and never as the port's failure for a document it could not read.
 */
final class ChildReadFailed extends RuntimeException {}
