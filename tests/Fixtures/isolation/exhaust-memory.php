<?php

declare(strict_types=1);

use App\Domain\Preparation\Isolation\ChildDocumentRead;

// A child that allocates until PHP's own memory_limit stops it: stands in for work whose
// allocations no budget ever sees, such as one native unpack of a very large string.
//
// It installs the entrypoint's own exhaustion handler and then turns error reporting off, so the
// fatal error's text never reaches the parent: the exit status is the only evidence left, which is
// what a child binary whose php.ini silences errors would give.
require dirname(__DIR__, 3).'/vendor/autoload.php';

ChildDocumentRead::exitWhenMemoryIsExhausted();
error_reporting(0);

$held = [];

while (true) {
    $held[] = str_repeat('x', 1_048_576);
}
