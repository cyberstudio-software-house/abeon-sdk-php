<?php

declare(strict_types=1);

use Abeon\SDK\Health\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'liveness'])->name('abeon.health.liveness');

// Readiness does real work — for Unified that is a fresh AMQP connection plus two outbox
// aggregates on every call — and it is unauthenticated by necessity, because a kubelet carries
// no credentials. The throttle is what stops anybody who can reach the pod from turning the
// probe into broker connection churn. Well above any sane probe interval.
Route::get('/health/ready', [HealthController::class, 'readiness'])
    ->middleware('throttle:60,1')
    ->name('abeon.health.readiness');
