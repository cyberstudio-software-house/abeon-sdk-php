<?php

declare(strict_types=1);

use Abeon\SDK\Health\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'liveness'])->name('abeon.health.liveness');

// Readiness does real work — for Unified that is a fresh AMQP connection plus two outbox
// aggregates on every call — and it is unauthenticated by necessity, because a kubelet carries
// no credentials. The throttle is what stops anybody who can reach the pod from turning the
// probe into broker connection churn. Well above any sane probe interval.
//
// **A named limiter, not `throttle:60,1`.** The unnamed form builds its key from
// `ThrottleRequests::resolveRequestSignature()`, which calls `$request->user()` — and that boots
// the configured guard. Two services here declare a guard on purpose without a driver, because
// identity comes from the token and reaching for `Auth::user()` is a bug they want to hear
// about; the probe then answered 500, which a kubelet reads as "not ready". `abeon-health` is
// registered by `AbeonServiceProvider` and keyed on the address.
Route::get('/health/ready', [HealthController::class, 'readiness'])
    ->middleware('throttle:abeon-health')
    ->name('abeon.health.readiness');
