<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Panel;

use Illuminate\Support\Facades\Http;

/**
 * Picking up a session on a client's host (ADR-0031 §3).
 *
 * The login screen is on another domain and its cookies stay there, so the browser arrives
 * with a one-time code and this application exchanges it for a session of its own.
 */
final class HandoffCallbackTest extends PanelTestCase
{
    public function test_a_code_becomes_the_platform_cookies(): void
    {
        Http::fake([
            '*/api/v1/auth/handoff/exchange' => Http::response(['data' => [
                'access_token'       => 'access.jwt',
                'refresh_token'      => 'refresh-token',
                'expires_in'         => 900,
                'refresh_expires_in' => 604800,
            ]]),
        ]);

        $response = $this->get('/auth/callback?code=abc&next=/settings')
            ->assertRedirect('/settings');

        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        $this->assertSame('access.jwt', $cookies['abeon_token']->getValue());
        $this->assertSame('refresh-token', $cookies['abeon_refresh']->getValue());
        // Host-only: this client's host holds the session, and no other host receives it.
        $this->assertNull($cookies['abeon_token']->getDomain());
        $this->assertTrue($cookies['abeon_token']->isHttpOnly());
    }

    public function test_the_host_is_sent_so_auth_can_check_the_code_belongs_here(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 900, 'refresh_expires_in' => 60,
        ]])]);

        $this->get('/auth/callback?code=abc');

        Http::assertSent(fn ($request) => $request['code'] === 'abc' && $request['host'] === 'localhost');
    }

    /**
     * `next` arrives in a URL somebody else wrote. An absolute address would make this an
     * open redirect that hands out a session on the way through.
     */
    public function test_next_can_only_point_inside_this_application(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 900, 'refresh_expires_in' => 60,
        ]])]);

        foreach (['https://evil.test/', '//evil.test/', '/\\evil.test'] as $next) {
            $this->get('/auth/callback?code=abc&next='.urlencode($next))->assertRedirect('/');
        }
    }

    public function test_a_refused_code_ends_at_the_login_screen_with_no_cookies(): void
    {
        Http::fake(['*' => Http::response(['type' => 'handoff-not-redeemable'], 400)]);

        $response = $this->get('/auth/callback?code=stale');

        $response->assertRedirectContains('/login');
        $names = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());
        $this->assertNotContains('abeon_token', $names);
        $this->assertNotContains('abeon_refresh', $names);
    }

    public function test_arriving_without_a_code_asks_nothing_of_auth(): void
    {
        Http::fake();

        $this->get('/auth/callback')->assertRedirectContains('/login');

        Http::assertNothingSent();
    }
}
