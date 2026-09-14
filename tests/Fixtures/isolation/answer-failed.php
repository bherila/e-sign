<?php

declare(strict_types=1);

// A child whose read threw something it did not anticipate. ChildDocumentRead::main() catches it and
// answers `kind: failed` with exit status zero, so the parent sees a decoded answer rather than a
// failed process. The message is the child's exception, which can name paths on the host.
echo serialize(['ok' => false, 'kind' => 'failed', 'code' => null, 'message' => 'RuntimeException: /opt/example/vendor/parser.php line 12']);
