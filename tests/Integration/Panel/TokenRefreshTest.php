<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Panel;

use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Panel\TokenRefresher;
use Illuminate\Support\Facades\Http;
use Mockery;
use Illuminate\Support\Facades\Cache;

/**
 * What happens when the access token expires under a user who is still working;
 * see docs/notes/token-refresh.md.
 */
final class TokenRefreshTest extends PanelTestCase
{
    private const EXPIRED = 'expired.jwt.token';

    private const FRESH = 'fresh.jwt.token';

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::fromArray([
            'id' => '3', 'email' => 'viewer@abeon.dev', 'name' => 'Dev Viewer',
            'roles' => ['viewer'], 'permissions' => ['panel.app.open'], 'org_id' => 1,
        ]);

        $validator = Mockery::mock(JwtValidator::class);
        $validator->shouldReceive('decodeUser')->with(self::FRESH)->andReturn($user);
        $validator->shouldReceive('decodeUser')->andThrow(AuthException::unauthenticated('Expired'));
        $this->app->instance(JwtValidator::class, $validator);
    }

    // ------------------------------------------------------------------ navigation

    public function test_an_expired_token_is_renewed_instead_of_bouncing_to_login(): void
    {
        $this->fakeSuccessfulRefresh();

        $response = $this
            ->withUnencryptedCookie('abeon_token', self::EXPIRED)
            ->withUnencryptedCookie('abeon_refresh', 'refresh-token-1')
            ->get('/');

        $response->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8138/api/v1/auth/refresh');
    }

    public function test_a_missing_access_cookie_still_refreshes(): void
    {
        // See docs/notes/token-refresh.md.
        $this->fakeSuccessfulRefresh();

        $this->withUnencryptedCookie('abeon_refresh', 'refresh-token-1')
            ->get('/')
            ->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8138/api/v1/auth/refresh');
        $this->assertCount(1, Http::recorded(
            fn ($request): bool => $request->url() === 'http://localhost:8138/api/v1/auth/refresh',
        ));
    }

    public function test_the_first_render_reads_preferences_with_the_renewed_token(): void
    {
        $this->fakeSuccessfulRefresh();

        $this->withUnencryptedCookie('abeon_token', self::EXPIRED)
            ->withUnencryptedCookie('abeon_refresh', 'refresh-token-1')
            ->get('/')
            ->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8138/api/v1/auth/me/preferences'
            && $request->hasHeader('Authorization', 'Bearer '.self::FRESH));
    }

    public function test_the_renewed_cookies_reach_the_browser(): void
    {
        // Without this the page renders once and the *next* request is expired again —
        // a refresh that nobody keeps is a refresh that runs on every single request.
        $this->fakeSuccessfulRefresh();

        $response = $this
            ->withUnencryptedCookie('abeon_token', self::EXPIRED)
            ->withUnencryptedCookie('abeon_refresh', 'refresh-token-1')
            ->get('/');

        $cookies = $response->headers->all('set-cookie');
        $joined = implode("\n", $cookies);

        $this->assertStringContainsString('abeon_token='.self::FRESH, $joined);
        $this->assertStringContainsString('abeon_refresh=refresh-token-2', $joined);
    }

    public function test_without_a_refresh_cookie_it_still_goes_to_login(): void
    {
        Http::fake();

        $this->withUnencryptedCookie('abeon_token', self::EXPIRED)
            ->get('/')
            ->assertRedirect('http://localhost:8140/login?redirect='.urlencode('http://localhost'));

        Http::assertNothingSent();
    }

    public function test_a_rejected_refresh_goes_to_login(): void
    {
        // The session really is over — the family was revoked, or the refresh token
        // has outlived its absolute TTL.
        Http::fake(['*' => Http::response(['title' => 'Unauthenticated'], 401)]);

        $this->withUnencryptedCookie('abeon_token', self::EXPIRED)
            ->withUnencryptedCookie('abeon_refresh', 'revoked-token')
            ->get('/')
            ->assertRedirect('http://localhost:8140/login?redirect='.urlencode('http://localhost'));
    }

    public function test_auth_being_unreachable_does_not_look_like_a_valid_session(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Connection refused'));

        $this->withUnencryptedCookie('abeon_token', self::EXPIRED)
            ->withUnencryptedCookie('abeon_refresh', 'refresh-token-1')
            ->get('/')
            ->assertRedirect('http://localhost:8140/login?redirect='.urlencode('http://localhost'));
    }

    // ------------------------------------------------------------------------ XHR

    public function test_a_relayed_call_retries_once_after_refreshing(): void
    {
        // The open-tab case: the bell polls, the cookie went stale underneath it, and
        // the browser cannot renew a cookie it is not allowed to read.
        Http::fakeSequence()
            ->push(['title' => 'Unauthenticated'], 401)
            ->push($this->refreshBody(), 200, ['Set-Cookie' => $this->refreshCookies()])
            ->push(['data' => [], 'meta' => ['unread_count' => 0]], 200);

        $response = $this->call('GET', '/api/v1/notifications', [], [
            'abeon_token'   => self::EXPIRED,
            'abeon_refresh' => 'refresh-token-1',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk();
        $this->assertStringContainsString(
            'abeon_token='.self::FRESH,
            implode("\n", $response->headers->all('set-cookie')),
        );
    }

    public function test_a_relayed_call_refreshes_when_the_access_cookie_is_already_gone(): void
    {
        // See docs/notes/token-refresh.md.
        Http::fakeSequence()
            ->push(['title' => 'Unauthenticated'], 401)
            ->push($this->refreshBody(), 200, ['Set-Cookie' => $this->refreshCookies()])
            ->push(['data' => ['unread_count' => 2]], 200);

        $this->call('GET', '/api/v1/notifications/unread-count', [], [
            'abeon_refresh' => 'refresh-token-1',
        ], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertOk()
            ->assertJson(['data' => ['unread_count' => 2]]);
    }

    public function test_a_relayed_call_does_not_retry_without_a_refresh_cookie(): void
    {
        Http::fake(['*' => Http::response(['title' => 'Unauthenticated'], 401)]);

        $this->call('GET', '/api/v1/notifications', [], ['abeon_token' => self::EXPIRED], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(401);

        // One attempt, no refresh: there is nothing to refresh with.
        Http::assertSentCount(1);
    }

    public function test_a_relayed_401_that_survives_the_retry_is_passed_through(): void
    {
        // Refreshing worked but the caller still is not allowed in. That is an answer,
        // not a failure, and rewriting it would hide a real authorisation problem.
        Http::fakeSequence()
            ->push(['title' => 'Unauthenticated'], 401)
            ->push($this->refreshBody(), 200, ['Set-Cookie' => $this->refreshCookies()])
            ->push(['title' => 'Unauthenticated'], 401);

        $this->call('GET', '/api/v1/notifications', [], [
            'abeon_token'   => self::EXPIRED,
            'abeon_refresh' => 'refresh-token-1',
        ], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(401);
    }

    public function test_the_browser_facing_refresh_goes_through_the_single_flight(): void
    {
        // One entry point means one call to Auth (ADR-0023); see docs/notes/token-refresh.md.
        $this->fakeSuccessfulRefresh();

        for ($i = 0; $i < 3; $i++) {
            $this->call('POST', '/api/v1/auth/refresh', [], [
                'abeon_refresh' => 'refresh-token-1',
            ], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'])->assertOk();
        }

        Http::assertSentCount(1);
    }

    public function test_the_browser_facing_refresh_answers_with_auths_own_body(): void
    {
        // Callers cannot tell that the refresh was served locally, which is the point:
        // the single-flight is an implementation detail, not a contract change.
        $this->fakeSuccessfulRefresh();

        $this->call('POST', '/api/v1/auth/refresh', [], ['abeon_refresh' => 'refresh-token-1'], [], [
            'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('data.access_token', self::FRESH);
    }

    public function test_a_failed_refresh_does_not_trigger_another_one(): void
    {
        // A 401 from a refresh means the presented token is spent. Retrying with the
        // same cookie is a second use of it — the replay this whole mechanism exists to
        // avoid.
        Http::fake(['*' => Http::response(['title' => 'Unauthenticated'], 401)]);

        $this->call('POST', '/api/v1/auth/refresh', [], ['abeon_refresh' => 'spent-token'], [], [
            'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(401);

        Http::assertSentCount(1);
    }

    public function test_the_refresh_cookie_reaches_auth(): void
    {
        // Auth reads the refresh token from a cookie, and the relay talks upstream with
        // a Bearer header — so without this the browser's own `POST /auth/refresh`
        // would arrive at Auth with nothing to refresh from.
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->call('POST', '/api/v1/auth/refresh', [], [
            'abeon_token'   => self::EXPIRED,
            'abeon_refresh' => 'refresh-token-1',
        ], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']);

        Http::assertSent(fn ($request): bool => str_contains(
            implode(',', $request->header('Cookie')),
            'abeon_refresh=refresh-token-1',
        ));
    }

    public function test_the_refresh_cookie_reaches_the_tenant_switch(): void
    {
        // The switch rotates the refresh family (ADR-0023 §2); see docs/notes/token-refresh.md.
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->call('POST', '/api/v1/auth/tenant', [], [
            'abeon_token'   => self::FRESH,
            'abeon_refresh' => 'refresh-token-1',
        ], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'], json_encode(['org_id' => 2]));

        Http::assertSent(fn ($request): bool => str_contains(
            implode(',', $request->header('Cookie')),
            'abeon_refresh=refresh-token-1',
        ));
    }

    public function test_the_refresh_cookie_reaches_no_other_auth_route(): void
    {
        // Even Auth only gets it where it reads it. `/auth/apps` is answered from the
        // Bearer header, so there is no reason for a seven-day credential to travel
        // with it — and every request that carries one is a request that can leak one.
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->call('GET', '/api/v1/auth/apps', [], [
            'abeon_token'   => self::FRESH,
            'abeon_refresh' => 'refresh-token-1',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        Http::assertSent(fn ($request): bool => ! str_contains(
            implode(',', $request->header('Cookie')),
            'refresh-token-1',
        ));
    }

    // -------------------------------------------------------------- single-flight

    public function test_concurrent_refreshes_call_auth_once(): void
    {
        // A second use of a consumed refresh token is a replay (ADR-0023);
        // see docs/notes/token-refresh.md.
        $this->fakeSuccessfulRefresh();

        $refresher = $this->app->make(TokenRefresher::class);

        $first = $refresher->attempt('refresh-token-1');
        $second = $refresher->attempt('refresh-token-1');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(self::FRESH, $second->accessToken);
        Http::assertSentCount(1);
    }

    public function test_a_different_session_is_refreshed_separately(): void
    {
        // The guard is per presented token, not global — two users expiring at the same
        // moment are two refreshes.
        $this->fakeSuccessfulRefresh();

        $refresher = $this->app->make(TokenRefresher::class);
        $refresher->attempt('refresh-token-1');
        $refresher->attempt('somebody-elses-token');

        Http::assertSentCount(2);
    }

    private function fakeSuccessfulRefresh(): void
    {
        Http::fake([
            '*/api/v1/auth/refresh' => Http::response(
                $this->refreshBody(),
                200,
                ['Set-Cookie' => $this->refreshCookies()],
            ),
            '*' => Http::response(['data' => []], 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshBody(): array
    {
        return ['data' => ['access_token' => self::FRESH, 'expires_in' => 900]];
    }

    /**
     * @return list<string>
     */
    private function refreshCookies(): array
    {
        return [
            'abeon_token='.self::FRESH.'; Path=/; HttpOnly; SameSite=Lax',
            'abeon_refresh=refresh-token-2; Path=/; HttpOnly; SameSite=Lax',
        ];
    }

    public function test_the_cache_never_holds_the_rotated_refresh_token(): void
    {
        // ADR-0023 §2; see docs/notes/token-refresh.md.
        Http::fake([
            '*/api/v1/auth/refresh' => Http::response(
                ['data' => ['access_token' => 'fresh-access-token']],
                200,
                ['Set-Cookie' => 'abeon_refresh=SEVEN-DAY-SECRET; Path=/; HttpOnly'],
            ),
        ]);

        $refreshed = app(TokenRefresher::class)->attempt('presented-refresh-token');

        // The caller still gets the cookies — the browser has to be told.
        $this->assertNotNull($refreshed);
        $this->assertNotEmpty($refreshed->setCookies);

        $stored = json_encode(Cache::get('abeon:refresh:'.hash('sha256', 'presented-refresh-token')));

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('SEVEN-DAY-SECRET', $stored);
        $this->assertStringNotContainsString('abeon_refresh', $stored);

        // The fifteen-minute credential does stay: it is what the waiting requests need,
        // and it is the difference between fifteen minutes and seven days that matters.
        $this->assertStringContainsString('fresh-access-token', $stored);
    }

    public function test_a_request_served_from_the_cache_sets_no_cookies(): void
    {
        Http::fake([
            '*/api/v1/auth/refresh' => Http::response(
                ['data' => ['access_token' => 'fresh-access-token']],
                200,
                ['Set-Cookie' => 'abeon_refresh=SEVEN-DAY-SECRET; Path=/; HttpOnly'],
            ),
        ]);

        $refresher = app(TokenRefresher::class);

        $refresher->attempt('presented-refresh-token');
        $second = $refresher->attempt('presented-refresh-token');

        $this->assertNotNull($second);
        $this->assertSame('fresh-access-token', $second->accessToken);
        $this->assertSame([], $second->setCookies, 'A cached hit has no cookies to give, and needs none.');

        // Still one call to Auth: the single-flight is what this cache is for, and it
        // has to keep working after the payload was trimmed.
        Http::assertSentCount(1);
    }

    public function test_a_post_is_not_replayed_after_a_401(): void
    {
        // Replay after refresh is for idempotent methods only; see docs/notes/service-proxy.md.
        Http::fake(['*' => Http::response(['title' => 'Unauthenticated'], 401)]);

        $this->call('POST', '/api/v1/notifications/mark-all-read', [], [
            'abeon_token'   => self::EXPIRED,
            'abeon_refresh' => 'refresh-token-1',
        ], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'])->assertStatus(401);

        // One call to Unified, and nothing to Auth: the relay did not even try to
        // refresh, because there would have been nothing safe to do with the result.
        Http::assertSentCount(1);
    }

    public function test_a_get_is_still_replayed_after_a_401(): void
    {
        // The case the retry exists for: the bell polls, the access cookie has expired
        // underneath it, and the browser cannot renew a cookie it cannot read.
        Http::fakeSequence()
            ->push(['title' => 'Unauthenticated'], 401)
            ->push($this->refreshBody(), 200, ['Set-Cookie' => $this->refreshCookies()])
            ->push(['data' => [], 'meta' => ['unread_count' => 0]], 200);

        $this->call('GET', '/api/v1/notifications', [], [
            'abeon_token'   => self::EXPIRED,
            'abeon_refresh' => 'refresh-token-1',
        ], [], ['HTTP_ACCEPT' => 'application/json'])->assertOk();
    }
}
