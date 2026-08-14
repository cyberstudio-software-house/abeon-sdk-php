<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\AuthMiddleware;
use Abeon\SDK\Auth\JwksClient;
use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Exceptions\AuthException;
use Firebase\JWT\JWT;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The user-facing half of the SDK's auth surface, which had no test of its own.
 *
 * Its behaviour was covered indirectly, by feature tests in `abeon-auth` and
 * `abeon-unified`. For a package whose every change touches every service on the
 * platform, that is the signal arriving in the wrong repository: a mistake here breaks
 * somebody else's build rather than this one's. It is the same mechanism that let the
 * `iss` defect survive until the first real service-to-service call.
 */
final class AuthMiddlewareTest extends TestCase
{
    private const KID = 'auth-test-kid';

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            $this->markTestSkipped('OpenSSL RSA key generation unavailable');
        }
        openssl_pkey_export($res, $privatePem);
        $this->privateKey = $privatePem;

        $details = openssl_pkey_get_details($res);
        $this->jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => self::KID,
            'n'   => $this->b64u($details['rsa']['n']),
            'e'   => $this->b64u($details['rsa']['e']),
            // Auth's own key. Only Auth may sign a user token.
            JwtValidator::JWK_OWNER => 'abeon-auth',
        ];
    }

    protected function tearDown(): void
    {
        JWT::$leeway = 0;

        parent::tearDown();
    }

    public function test_a_valid_user_token_populates_the_context(): void
    {
        $context = new AuthContext();

        $response = $this->middleware($context)->handle($this->request($this->token()), fn () => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame('42', $context->user()?->id);
        $this->assertSame(7, $context->user()?->orgId);
    }

    public function test_a_service_token_is_refused(): void
    {
        // The mirror of `ServiceAuthMiddleware` refusing a user token: an internal
        // credential must not open a user-facing route either.
        $token = $this->token(['type' => 'service', 'service_name' => 'crm', 'iss' => 'crm']);

        $this->expectException(AuthException::class);

        $this->middleware(new AuthContext())->handle($this->request($token), fn () => new Response('ok'));
    }

    public function test_no_header_is_refused(): void
    {
        $this->expectException(AuthException::class);

        $this->middleware(new AuthContext())->handle(Request::create('/'), fn () => new Response('ok'));
    }

    public function test_a_user_token_without_an_organisation_is_refused(): void
    {
        // ADR-0016 makes `org_id` an authorisation dimension, so a missing one has to
        // fail at the trust boundary rather than deeper, where "no tenant" is one
        // mistake from "every tenant" (ADR-0018).
        $claims = $this->claims();
        unset($claims['org_id']);

        $this->expectException(AuthException::class);

        $this->middleware(new AuthContext())->handle(
            $this->request(JWT::encode($claims, $this->privateKey, 'RS256', self::KID)),
            fn () => new Response('ok'),
        );
    }

    public function test_the_bearer_scheme_is_matched_without_regard_to_case(): void
    {
        // RFC 7235 defines the scheme as case-insensitive; `str_starts_with($header,
        // 'Bearer ')` is not. A caller sending `bearer …` — an integration written
        // outside PHP, or a proxy that normalises header casing — got a 401 saying it
        // had presented no token, which is a long way from the cause. Nothing internal
        // hits it, because `ServiceClient` always writes `Bearer`, which is exactly why
        // it would have cost somebody outside a day.
        $context = new AuthContext();
        $request = Request::create('/');
        $request->headers->set('Authorization', 'bearer '.$this->token());

        $this->middleware($context)->handle($request, fn () => new Response('ok'));

        $this->assertSame('42', $context->user()?->id);
    }

    public function test_a_header_that_is_not_a_bearer_is_refused(): void
    {
        $request = Request::create('/');
        $request->headers->set('Authorization', 'Basic '.base64_encode('user:pass'));

        $this->expectException(AuthException::class);

        $this->middleware(new AuthContext())->handle($request, fn () => new Response('ok'));
    }

    private function middleware(AuthContext $context): AuthMiddleware
    {
        return new AuthMiddleware($this->validator(), $context);
    }

    private function validator(): JwtValidator
    {
        $config = new AbeonConfig(new Repository([
            'abeon' => ['auth' => ['issuer' => 'abeon-auth', 'audience' => 'abeon']],
        ]));

        return new JwtValidator(new StaticJwksClient([self::KID => $this->jwk]), $config);
    }

    private function request(string $token): Request
    {
        $request = Request::create('/thing');
        $request->headers->set('Authorization', "Bearer {$token}");

        return $request;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function token(array $overrides = []): string
    {
        return JWT::encode($this->claims($overrides), $this->privateKey, 'RS256', self::KID);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return array_merge([
            // A user token: minted by Auth, signed with Auth's key, carrying the tenant.
            'iss'    => 'abeon-auth',
            'aud'    => 'abeon',
            'sub'    => '42',
            'type'   => 'user',
            'org_id' => 7,
            'email'  => 'user@abeon.dev',
            'name'   => 'Test User',
            'iat'    => time() - 60,
            'exp'    => time() + 600,
            'jti'    => 'user-jti-1',
        ], $overrides);
    }

    private function b64u(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
