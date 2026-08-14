<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Http;

use Abeon\SDK\AbeonServiceProvider;
use Abeon\SDK\Http\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

/**
 * Which proxies a service believes, and that it believes them at all.
 *
 * The failure this guards against is invisible in development: over plain HTTP the
 * forwarded headers agree with what the application already thinks, so everything
 * works. It only appears behind a TLS-terminating ingress, where the application
 * decides the request came in over HTTP — and then the return-to parameter stops
 * surviving login, cookies lose `Secure`, and generated links point at `http://`.
 */
final class TrustedProxiesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AbeonServiceProvider::class];
    }

    protected function tearDown(): void
    {
        // Static state on the framework's middleware, so it outlives the application.
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_a_forwarded_request_is_believed_by_default(): void
    {
        $request = Request::create('http://pod-internal/settings');
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->headers->set('X-Forwarded-Host', 'app.abeon.pl');
        $request->server->set('REMOTE_ADDR', '10.42.0.7');

        $handled = (new TrustProxies())->handle($request, fn (Request $r): Request => $r);

        $this->assertSame('https://app.abeon.pl/settings', $handled->fullUrl());
        $this->assertTrue($handled->isSecure());
    }

    public function test_the_forwarded_address_is_the_client_not_the_ingress(): void
    {
        // The reason this is not only cosmetic: Auth throttles failed logins per
        // `(email, ip)`, and without trusted proxies every request on the platform
        // arrives from the ingress — one address, one counter, shared by everybody.
        $request = Request::create('http://pod-internal/login');
        $request->headers->set('X-Forwarded-For', '203.0.113.9');
        $request->server->set('REMOTE_ADDR', '10.42.0.7');

        $handled = (new TrustProxies())->handle($request, fn (Request $r): Request => $r);

        $this->assertSame('203.0.113.9', $handled->ip());
    }

    public function test_a_pinned_list_refuses_a_proxy_that_is_not_on_it(): void
    {
        // And the reason `*` is a choice rather than a default worth ignoring: with it,
        // anything that can reach the pod directly picks its own address, so the counter
        // above becomes spoofable. Pinning the ingress range takes that back.
        config(['abeon.http.trusted_proxies' => '10.42.0.0/16']);
        TrustedProxies::apply();

        $fromIngress = Request::create('http://pod-internal/login');
        $fromIngress->headers->set('X-Forwarded-For', '203.0.113.9');
        $fromIngress->server->set('REMOTE_ADDR', '10.42.0.7');

        $direct = Request::create('http://pod-internal/login');
        $direct->headers->set('X-Forwarded-For', '203.0.113.9');
        $direct->server->set('REMOTE_ADDR', '198.51.100.4');

        $middleware = new TrustProxies();

        $this->assertSame('203.0.113.9', $middleware->handle($fromIngress, fn ($r) => $r)->ip());
        $this->assertSame('198.51.100.4', $middleware->handle($direct, fn ($r) => $r)->ip());
    }

    public function test_the_configured_value_is_parsed_into_a_list(): void
    {
        config(['abeon.http.trusted_proxies' => ' 10.0.0.0/8 , 192.168.0.0/16 ']);
        $this->assertSame(['10.0.0.0/8', '192.168.0.0/16'], TrustedProxies::proxies());

        config(['abeon.http.trusted_proxies' => '*']);
        $this->assertSame('*', TrustedProxies::proxies());

        // An operator who sets `ABEON_TRUSTED_PROXIES=` means "unset", not "trust
        // nothing" — and trusting nothing is the broken state this class exists to
        // prevent, so an empty value falls back to the default rather than to silence.
        config(['abeon.http.trusted_proxies' => '']);
        $this->assertSame('*', TrustedProxies::proxies());

        config(['abeon.http.trusted_proxies' => ['10.0.0.0/8']]);
        $this->assertSame(['10.0.0.0/8'], TrustedProxies::proxies());
    }
}
