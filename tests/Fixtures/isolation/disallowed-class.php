<?php

declare(strict_types=1);

// A child that answers with a well-formed message naming a class outside the response allowlist.
// The parent must refuse it rather than instantiate it.
echo serialize(['ok' => true, 'value' => new ArrayObject(['smuggled'])]);
