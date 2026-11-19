<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Panel;

use Illuminate\Support\Facades\Http;

/**
 * The topbar bell now reads AbeonUnified (ADR-0006/ADR-0019) instead of the local
 * dev stub. The chrome hook calls this application's own origin, so these routes
 * have to exist here — in production the ingress routes them directly.
 */
final class NotificationProxyTest extends PanelTestCase
{
    public function test_it_forwards_the_feed_request_to_unified(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['unread_count' => 0]], 200)]);

        // NB: `call()` with the explicit cookies arg is used instead of `getJson()` —
        // the JSON test helpers drop `withUnencryptedCookie`, and `abeon_token` is
        // excepted from cookie encryption.
        $this->call('GET', '/api/v1/notifications', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://localhost:8141/api/v1/notifications'
                && $request->hasHeader('Authorization', 'Bearer test.jwt.token');
        });
    }

    public function test_it_forwards_the_unread_count(): void
    {
        Http::fake(['*' => Http::response(['data' => ['unread_count' => 3]], 200)]);

        $this->call('GET', '/api/v1/notifications/unread-count', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])
            ->assertOk()
            // The field is `unread_count`, per ADR-0006. The retired stub answered
            // `count`, matching the chrome hook rather than the contract — the two
            // agreed with each other and with nothing else.
            ->assertJson(['data' => ['unread_count' => 3]]);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8141/api/v1/notifications/unread-count');
    }

    public function test_it_forwards_mark_as_read(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'abc']], 200)]);

        $this->call('PATCH', '/api/v1/notifications/abc/read', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json',
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://localhost:8141/api/v1/notifications/abc/read'
                && $request->method() === 'PATCH';
        });
    }

    public function test_it_forwards_mark_all_read(): void
    {
        Http::fake(['*' => Http::response(['data' => ['marked' => 2]], 200)]);

        $this->call('POST', '/api/v1/notifications/mark-all-read', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json',
        ])->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8141/api/v1/notifications/mark-all-read'
            && $request->method() === 'POST');
    }

    public function test_a_change_from_another_origin_is_refused(): void
    {
        Http::fake(['*' => Http::response(['data' => ['marked' => 2]], 200)]);
        $server = ['HTTP_ACCEPT' => 'application/json'];

        $this->call('POST', '/api/v1/notifications/mark-all-read', [], ['abeon_token' => 't'], [], $server)->assertForbidden();
        $this->call('POST', '/api/v1/notifications/mark-all-read', [], ['abeon_token' => 't'], [], [...$server, 'HTTP_ORIGIN' => 'https://evil.test'])->assertForbidden();
        $this->call('POST', '/api/v1/auth/tenant', [], ['abeon_token' => 't'], [], [...$server, 'HTTP_REFERER' => 'https://evil.test/x'])->assertForbidden();
        Http::assertNothingSent();

        $this->call('POST', '/api/v1/notifications/mark-all-read', [], ['abeon_token' => 't'], [], [...$server, 'HTTP_REFERER' => 'http://localhost/forms'])->assertOk();
    }

    public function test_the_refresh_cookie_never_reaches_unified(): void
    {
        // Siedmiodniowe poświadczenie, które Auth trzyma u siebie zahaszowane. Do 14.08
        // relay doklejał je do **każdego** wywołania w górę, więc szło otwartym tekstem
        // do Unified przy każdym odpytaniu dzwonka — czyli na każdym ładowaniu strony.
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->call('GET', '/api/v1/notifications', [], [
            'abeon_token'   => 'test.jwt.token',
            'abeon_refresh' => 'siedmiodniowe-poswiadczenie',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        Http::assertSent(fn ($request): bool => ! str_contains(
            implode(',', $request->header('Cookie')),
            'siedmiodniowe-poswiadczenie',
        ));
    }

    public function test_unified_cannot_set_cookies_on_this_origin(): void
    {
        // Unified odpowiada na tym samym originie co aplikacja, więc przepuszczony
        // `Set-Cookie` pozwalał mu nadpisać `abeon_token` i zafiksować sesję każdemu,
        // komu obsłużył dzwonek. Ciasteczka wystawia wyłącznie serwis, który jest
        // właścicielem sesji.
        Http::fake(['*' => Http::response(['data' => []], 200, [
            'Set-Cookie' => ['abeon_token=podstawiony; Path=/', 'evil=1; Path=/'],
        ])]);

        $response = $this->call('GET', '/api/v1/notifications', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $joined = implode("\n", $response->headers->all('set-cookie'));
        $this->assertStringNotContainsString('podstawiony', $joined);
        $this->assertStringNotContainsString('evil=1', $joined);
    }

    public function test_an_upstream_failure_is_passed_through_rather_than_masked(): void
    {
        // The bell must degrade visibly. Rewriting a 503 into an empty feed would be
        // indistinguishable from "you have no notifications", which is the failure
        // mode nobody notices.
        Http::fake(['*' => Http::response(['title' => 'Service Unavailable'], 503)]);

        $this->call('GET', '/api/v1/notifications', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(503);
    }

    public function test_the_relay_refuses_to_leave_its_own_prefix(): void
    {
        // The route pattern is `.*`, so `..` segments arrive intact and Guzzle resolves
        // them when it builds the URI: `/api/v1/notifications/../../auth/secret` reached
        // AbeonUnified as `/api/auth/secret`, verified with `curl --path-as-is`. Limited
        // impact today — Unified's internal routes want a service token this relay does
        // not have — but it was the only boundary this code had.
        Http::fake();

        $this->call('GET', '/api/v1/notifications/../../auth/secret', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_encoded_traversal_is_refused_rather_than_decoded(): void
    {
        // `%2e%2e%2f` passes through here literally and is resolved by whatever sits
        // upstream, so the only place that can judge it is the one that refuses to
        // forward it.
        Http::fake();

        $this->call('GET', '/api/v1/notifications/%2e%2e%2fauth', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_the_query_string_survives_a_method_with_a_body(): void
    {
        // It was passed as an options array on the `GET` branch alone, so every other
        // method silently dropped it — and only through this relay, which is the local
        // path; in production the ingress routes `/api/v1/*` past this code entirely.
        Http::fake(['*' => Http::response(['data' => ['marked' => 3]], 200)]);

        $this->call('POST', '/api/v1/notifications/mark-all-read?before=2026-08-01', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json',
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'before=2026-08-01'));
    }

    public function test_a_429_keeps_its_retry_after(): void
    {
        // Only `Content-Type` used to come back, which loses `Retry-After` on exactly
        // the 429s this platform now produces — the notification emit cap and the login
        // throttle — and `WWW-Authenticate` on a 401.
        Http::fake(['*' => Http::response(['title' => 'Too Many Requests'], 429, ['Retry-After' => '30'])]);

        $this->call('GET', '/api/v1/notifications', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(429)->assertHeader('Retry-After', '30');
    }

    public function test_it_carries_the_pages_correlation_id_upstream_and_returns_what_unified_answered(): void
    {
        $correlationId = '5f2b1c94-6d3a-4c7e-9a1b-0c8d2e3f4a5b';

        Http::fake([
            '*' => Http::response(['data' => []], 200, [
                'X-API-Version'     => 'v1',
                'X-Correlation-ID'  => $correlationId,
                'Deprecation'       => 'true',
                'Sunset'            => 'Wed, 01 Jan 2027 00:00:00 GMT',
            ]),
        ]);

        $response = $this->call('GET', '/api/v1/notifications', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ACCEPT'           => 'application/json',
            'HTTP_X_CORRELATION_ID' => $correlationId,
        ]);

        $response->assertOk()
            ->assertHeader('X-API-Version', 'v1')
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset', 'Wed, 01 Jan 2027 00:00:00 GMT')
            ->assertHeader('X-Correlation-ID', $correlationId);

        Http::assertSent(fn ($request): bool => $request->header('X-Correlation-ID') === [$correlationId]);
    }
}
