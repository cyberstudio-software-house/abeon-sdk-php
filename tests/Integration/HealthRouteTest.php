<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration;

use Abeon\SDK\AbeonServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * The readiness route as a kubelet meets it: unauthenticated, on a service whose guard cannot
 * be resolved.
 *
 * Two services on this platform declare a guard with no driver on purpose — identity comes from
 * the token, and reaching for `Auth::user()` is a bug they want to hear about. The probe then
 * has to answer without ever asking who is calling. It did not: `throttle:60,1` builds its key
 * through `ThrottleRequests::resolveRequestSignature()`, which calls `$request->user()`, boots
 * the guard and throws — so readiness answered 500 and the pod read as not ready, in both
 * services at once, while every suite stayed green because only one service covered this route
 * and that one has a working guard.
 */
final class HealthRouteTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AbeonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);
        $app['config']->set('abeon.service.name', 'crm');
        $app['config']->set('abeon.health.checks', []);

        // `abeon-unified`'s configuration, verbatim in shape: a guard that exists so that using
        // it is an immediate error rather than a silent null.
        $app['config']->set('auth.defaults.guard', 'abeon');
        $app['config']->set('auth.guards', ['abeon' => ['driver' => null, 'provider' => null]]);
        $app['config']->set('auth.providers', []);
    }

    public function test_readiness_answers_without_resolving_a_user(): void
    {
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_liveness_answers_too(): void
    {
        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    /**
     * The limit stays — readiness does real work, and anybody who can reach the pod could
     * otherwise turn it into broker connection churn.
     */
    public function test_readiness_is_still_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/health/ready')->assertOk();
        }

        $this->getJson('/health/ready')->assertStatus(429);
    }
}
