<?php

declare(strict_types=1);
use App\Domain\Preparation\Isolation\ChildDocumentRead;

/*
 * Entrypoint for a process-bounded document read (docs/adr/0006-process-bounded-document-reads.md).
 *
 * Started by App\Domain\Preparation\Isolation\ChildProcessDocumentReader with a hard
 * `-d memory_limit` and killed at a wall-clock deadline. Reads one request on standard input and
 * writes one response on standard output; see App\Domain\Preparation\Isolation\ChildDocumentRead.
 *
 * Lives under app/ rather than a top-level bin/ because the shared-hosting release bundle ships
 * app/ and would not ship a new top-level directory.
 */

require dirname(__DIR__, 4).'/vendor/autoload.php';

exit(ChildDocumentRead::main(STDIN, STDOUT));
