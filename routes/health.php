<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// `/up` (registered in bootstrap/app.php) is Laravel's minimal liveness probe: no
// database, no dependencies, safe for a load balancer to poll every few seconds.
// `/health/ready` below is the detailed readiness probe (issue #16).
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
