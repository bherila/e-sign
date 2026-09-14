<?php

declare(strict_types=1);

use App\Domain\Preparation\Isolation\ChildDocumentRead;

// The real child, after a pause longer than one read's deadline in the test that starts it: stands
// in for an operation that legitimately spends more than one budget's worth of time, so the only
// question is whether its hard deadline allows for that.
require dirname(__DIR__, 3).'/vendor/autoload.php';

usleep(2_500_000);

exit(ChildDocumentRead::main(STDIN, STDOUT));
