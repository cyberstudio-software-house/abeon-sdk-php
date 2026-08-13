<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth;

use Abeon\SDK\Auth\JwksClient;
use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\Auth\ServiceAuthMiddleware;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Exceptions\AuthException;
use Firebase\JWT\JWT;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ServiceAuthMiddlewareTest extends TestCase
{
    private const KID = 'service-test-kid';

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
        ];
    }

    protected function tearDown(): void
    {
        JWT::$leeway = 0;

        parent::tearDown();
    }

    public function test_admits_a_valid_service_token_and_exposes_the_caller(): void
    {
        $request = $this->request($this->token());

        $response = $this->middleware()->handle($request, fn () => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame('crm', $request->attributes->get(ServiceAuthMiddleware::ATTRIBUTE));
    }

    public function test_rejects_a_user_token(): void
    {
        // The whole point of the middleware. Without this check, every holder of a
        // normal session could call the internal surface, which is the opposite of
        // what "internal" means.
        $token = $this->token(['type' => 'user', 'sub' => '42', 'org_id' => 7]);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Expected service-type JWT');

        $this->middleware()->handle($this->request($token), fn () => new Response('ok'));
    }

    public function test_rejects_a_service_token_without_a_service_name(): void
    {
        $claims = $this->claims();
        unset($claims['service_name']);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('missing service_name');

        $this->middleware()->handle(
            $this->request(JWT::encode($claims, $this->privateKey, 'RS256', self::KID)),
            fn () => new Response('ok'),
        );
    }

    public function test_rejects_a_request_without_a_bearer_token(): void
    {
        $this->expectException(AuthException::class);

        $this->middleware()->handle(Request::create('/internal/thing', 'POST'), fn () => new Response('ok'));
    }

    public function test_rejects_a_token_this_service_cannot_verify(): void
    {
        // Signed with a key the JWKS does not publish — an unknown issuer, not a
        // trusted service. The validator raises before the type check ever runs.
        $foreign = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($foreign, $foreignPem);
        $token = JWT::encode($this->claims(), $foreignPem, 'RS256', self::KID);

        $this->expectException(AuthException::class);

        $this->middleware()->handle($this->request($token), fn () => new Response('ok'));
    }

    private function middleware(): ServiceAuthMiddleware
    {
        $config = new AbeonConfig(new Repository([
            'abeon' => ['auth' => ['issuer' => 'abeon-auth', 'audience' => 'abeon']],
        ]));

        return new ServiceAuthMiddleware(
            new JwtValidator(new StaticJwksClient([self::KID => $this->jwk]), $config),
        );
    }

    private function request(string $token): Request
    {
        $request = Request::create('/internal/thing', 'POST');
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
            'iss'          => 'abeon-auth',
            'aud'          => 'abeon',
            'sub'          => 'crm',
            'type'         => 'service',
            'service_name' => 'crm',
            'iat'          => time() - 60,
            'exp'          => time() + 600,
            'jti'          => 'svc-jti-1',
        ], $overrides);
    }

    private function b64u(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}

/**
 * Serves a fixed key set with no HTTP or cache, following the package's
 * FakeTokenProvider idiom.
 */
final class StaticJwksClient extends JwksClient
{
    /**
     * @param  array<string, array<string, mixed>>  $keys  keyed by kid
     */
    public function __construct(private array $keys)
    {
        // Intentionally skip the parent constructor — no HTTP/cache in unit tests.
    }

    public function findKey(string $kid): ?array
    {
        return $this->keys[$kid] ?? null;
    }

    public function flush(): void
    {
    }
}
