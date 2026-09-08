<?php

namespace App\Traits;

use DateTimeInterface;

trait SerializesDatesAsLocal
{
    /**
     * Prepare a date for array / JSON serialization.
     *
     * This ensures that dates are serialized without timezone information (Z or offsets),
     * which prevents JavaScript from shifting them when creating Date objects.
     * This results in "as stored" or "local" interpretation in the browser.
     *
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
