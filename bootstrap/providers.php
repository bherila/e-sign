<?php

use App\Providers\AppServiceProvider;
use App\Providers\DeliveryServiceProvider;
use App\Providers\EvidenceServiceProvider;
use App\Providers\HealthServiceProvider;

return [
    AppServiceProvider::class,
    DeliveryServiceProvider::class,
    EvidenceServiceProvider::class,
    HealthServiceProvider::class,
];
