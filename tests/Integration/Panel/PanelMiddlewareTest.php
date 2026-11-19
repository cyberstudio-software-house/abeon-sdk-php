<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Panel;

use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Panel\WrongOrganisation;
use Illuminate\Support\Facades\Http;
use Mockery;

final class PanelMiddlewareTest extends PanelTestCase
{
    /**
     * @param  list<string>  $permissions
     */
    private function signIn(array $permissions = ['panel.app.open']): void
    {
        $validator = Mockery::mock(JwtValidator::class);
        $validator->shouldReceive('decodeUser')->andReturn(User::fromArray([
            'id' => '1', 'email' => 'admin@abeon.dev', 'name' => 'Dev Admin',
            'roles' => ['admin'], 'permissions' => $permissions, 'org_id' => 1,
        ]));
        $this->app->instance(JwtValidator::class, $validator);
    }

    public function test_an_entitled_user_gets_the_page_with_the_abeon_props(): void
    {
        $this->signIn();
        Http::fake(['*/api/v1/auth/me/preferences' => Http::response(['data' => ['version' => 1, 'chrome' => []]])]);

        $this->withUnencryptedCookie('abeon_token', 't')->get('/')
            ->assertOk()
            ->assertJsonPath('appId', 'panel')
            ->assertJsonPath('user.email', 'admin@abeon.dev')
            ->assertJsonPath('loginUrl', 'http://localhost:8140/login')
            ->assertJsonPath('preferences.version', 1);
    }

    public function test_an_inertia_visit_skips_the_preferences_read(): void
    {
        $this->signIn();
        Http::fake();

        $this->withUnencryptedCookie('abeon_token', 't')->withHeaders(['X-Inertia' => 'true'])->get('/')
            ->assertJsonPath('preferences', null);
        Http::assertNothingSent();
    }

    public function test_entitlement_needs_a_permission_under_the_app_prefix(): void
    {
        foreach ([['crm.contacts.read'], ['*'], ['*.*.*'], ['*.app.open']] as $permissions) {
            $this->signIn($permissions);
            $this->withUnencryptedCookie('abeon_token', 't')->get('/settings')->assertForbidden();
        }
    }

    public function test_an_application_with_no_identity_refuses_to_serve(): void
    {
        config(['abeon.app_descriptor.name' => null, 'abeon.service.name' => '']);
        $this->signIn();

        $this->withUnencryptedCookie('abeon_token', 't')->get('/settings')->assertStatus(500);
    }

    public function test_an_inertia_visit_without_a_session_gets_a_location_answer(): void
    {
        $this->get('/settings', ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'http://localhost:8140/login?redirect='.urlencode('http://localhost/settings'));
    }

    public function test_a_token_for_another_organisation_is_its_own_refusal(): void
    {
        config(['abeon.service.org_id' => '7']);
        $validator = Mockery::mock(JwtValidator::class);
        $validator->shouldReceive('decodeUser')->andThrow(AuthException::wrongOrganisation(8, 7));
        $this->app->instance(JwtValidator::class, $validator);
        $this->withoutExceptionHandling();

        try {
            $this->withUnencryptedCookie('abeon_token', 't')->get('/settings');
            $this->fail('Expected WrongOrganisation.');
        } catch (WrongOrganisation $e) {
            $this->assertSame(7, $e->instanceOrgId);
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_the_panel_is_not_served_on_the_public_host(): void
    {
        config(['abeon.public_host' => 'www.client.test']);

        $this->get('http://www.client.test/settings')->assertNotFound();
    }

    public function test_panel_responses_carry_the_security_headers(): void
    {
        $this->get('/settings')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_the_session_cookies_stay_under_the_path_prefix(): void
    {
        $this->signIn();

        $response = $this->withUnencryptedCookie('abeon_token', 't')
            ->withHeaders(['X-Forwarded-Prefix' => '/cms', 'X-Forwarded-Host' => 'panel.acme.com', 'X-Forwarded-Proto' => 'https'])
            ->get('/settings')
            ->assertOk();

        $paths = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $paths[$cookie->getName()] = $cookie->getPath();
        }
        $this->assertSame('/cms', $paths['XSRF-TOKEN'] ?? null);
    }
}
