<?php

declare(strict_types=1);

use Abeon\SDK\Health\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health',       [HealthController::class, 'liveness'])->name('abeon.health.liveness');
Route::get('/health/ready', [HealthController::class, 'readiness'])->name('abeon.health.readiness');
