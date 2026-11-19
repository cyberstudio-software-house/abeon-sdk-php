<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Panel;

use Illuminate\Support\Facades\Http;

final class AuthProxyTest extends PanelTestCase
{
    public function test_it_forwards_get_requests_to_the_auth_service(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [['name' => 'crm']]], 200),
        ]);

        // NB: `call()` with the explicit cookies arg is used instead of
        // `getJson()` — the JSON test helpers drop `withUnencryptedCookie`,
        // and `abeon_token` is excepted from cookie encryption.
        $this->call('GET', '/api/v1/auth/apps', [], ['abeon_token' => 'test.jwt.token'], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])
            ->assertOk()
            ->assertJson(['data' => [['name' => 'crm']]]);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8138/api/v1/auth/apps'
                && $request->hasHeader('Authorization', 'Bearer test.jwt.token');
        });
    }

    public function test_it_forwards_patch_body_to_the_auth_service(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['version' => 1]], 200),
        ]);

        $this->call(
            'PATCH',
            '/api/v1/auth/me/preferences',
            [],
            ['abeon_token' => 'test.jwt.token'],
            [],
            ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['chrome' => ['theme' => 'dark']]),
        )->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8138/api/v1/auth/me/preferences'
                && $request->method() === 'PATCH'
                && str_contains($request->body(), 'dark');
        });
    }
}
