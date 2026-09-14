<?php

declare(strict_types=1);

// A child that allocates until PHP's own memory_limit stops it: stands in for work whose
// allocations no budget ever sees, such as one native unpack of a very large string.
$held = [];

while (true) {
    $held[] = str_repeat('x', 1_048_576);
}
