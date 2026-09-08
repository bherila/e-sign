<?php

use App\Providers\AppServiceProvider;
use App\Providers\DeliveryServiceProvider;
use App\Providers\EvidenceServiceProvider;
use App\Providers\HealthServiceProvider;
use App\Providers\PreparationServiceProvider;
use App\Providers\SigningServiceProvider;

return [
    AppServiceProvider::class,
    DeliveryServiceProvider::class,
    EvidenceServiceProvider::class,
    HealthServiceProvider::class,
    PreparationServiceProvider::class,
    SigningServiceProvider::class,
];
