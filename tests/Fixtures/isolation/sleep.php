<?php

declare(strict_types=1);

// A child that never answers: stands in for a read whose loop reports nothing. The parent's
// wall-clock deadline is the only thing that can stop it.
sleep(60);
