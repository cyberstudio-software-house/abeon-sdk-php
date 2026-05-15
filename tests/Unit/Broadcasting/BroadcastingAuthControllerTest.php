<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Broadcasting;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\Broadcasting\BroadcastingAuthController;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class BroadcastingAuthControllerTest extends TestCase
{
    public function test_rejects_request_without_access_cookie(): void
    {
        $controller = $this->controller(
            jwtUser: null,
            broadcastResult: new JsonResponse(['auth' => 'irrelevant']),
        );

        $this->expectException(AuthException::class);
        $controller(Request::create('/broadcasting/auth', 'POST'));
    }

    public function test_authorises_user_channel_when_sub_matches(): void
    {
        $user = $this->user(id: '42');
        $controller = $this->controller(
            jwtUser: $user,
            broadcastResult: new JsonResponse(['auth' => 'reverb-signed-token']),
        );

        $request = Request::create('/broadcasting/auth', 'POST', [
            'socket_id'    => 'abc.123',
            'channel_name' => 'private-user.42',
        ]);
        $request->cookies->set('abeon_token', 'valid-jwt');

        $response = $controller($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(['auth' => 'reverb-signed-token'], $response->getData(true));
    }

    public function test_rejects_user_channel_when_sub_does_not_match(): void
    {
        $controller = $this->controller(
            jwtUser: $this->user(id: '42'),
            broadcastResult: new JsonResponse(['auth' => 'never']),
        );

        $request = Request::create('/broadcasting/auth', 'POST', [
            'socket_id'    => 'abc.123',
            'channel_name' => 'private-user.99',
        ]);
        $request->cookies->set('abeon_token', 'valid-jwt');

        $this->expectException(AuthException::class);
        $controller($request);
    }

    public function test_authorises_org_channel_when_claim_matches(): void
    {
        $controller = $this->controller(
            jwtUser: $this->user(id: '42', orgId: 7),
            broadcastResult: new JsonResponse(['auth' => 'reverb-org']),
        );

        $request = Request::create('/broadcasting/auth', 'POST', [
            'socket_id'    => 'abc.123',
            'channel_name' => 'private-org.7',
        ]);
        $request->cookies->set('abeon_token', 'valid-jwt');

        $response = $controller($request);
        $this->assertSame(['auth' => 'reverb-org'], $response->getData(true));
    }

    public function test_delegates_unknown_channels_to_laravel_broadcaster(): void
    {
        $broadcast = $this->createMock(Broadcaster::class);
        $broadcast->expects($this->once())
            ->method('auth')
            ->willReturn(new JsonResponse(['auth' => 'service-channel']));

        $controller = new BroadcastingAuthController(
            jwt:         $this->makeJwt($this->user(id: '42')),
            authContext: new AuthContext(),
            config:      $this->makeConfig(),
            broadcaster: $broadcast,
        );

        $request = Request::create('/broadcasting/auth', 'POST', [
            'socket_id'    => 'abc.123',
            'channel_name' => 'private-crm.deal.xyz',
        ]);
        $request->cookies->set('abeon_token', 'valid-jwt');

        $response = $controller($request);
        $this->assertSame(['auth' => 'service-channel'], $response->getData(true));
    }

    public function test_sets_auth_context_for_channel_policies(): void
    {
        $user = $this->user(id: '42');
        $authContext = new AuthContext();

        $broadcast = $this->createMock(Broadcaster::class);
        $broadcast->method('auth')->willReturn(new JsonResponse(['auth' => 'ok']));

        $controller = new BroadcastingAuthController(
            jwt:         $this->makeJwt($user),
            authContext: $authContext,
            config:      $this->makeConfig(),
            broadcaster: $broadcast,
        );

        $request = Request::create('/broadcasting/auth', 'POST', [
            'socket_id'    => 'abc.123',
            'channel_name' => 'private-user.42',
        ]);
        $request->cookies->set('abeon_token', 'valid-jwt');

        $controller($request);

        $this->assertSame($user, $authContext->user());
        $this->assertSame($user, $request->user());
    }

    private function controller(?User $jwtUser, JsonResponse $broadcastResult): BroadcastingAuthController
    {
        $broadcast = $this->createMock(Broadcaster::class);
        $broadcast->method('auth')->willReturn($broadcastResult);

        return new BroadcastingAuthController(
            jwt:         $this->makeJwt($jwtUser),
            authContext: new AuthContext(),
            config:      $this->makeConfig(),
            broadcaster: $broadcast,
        );
    }

    private function makeJwt(?User $user): JwtValidator
    {
        $stub = $this->createMock(JwtValidator::class);
        if ($user === null) {
            $stub->method('decodeUser')->willThrowException(AuthException::unauthenticated());
        } else {
            $stub->method('decodeUser')->willReturn($user);
        }

        return $stub;
    }

    private function makeConfig(): AbeonConfig
    {
        return new AbeonConfig(new Repository([
            'abeon' => [
                'service' => ['name' => 'crm'],
                'auth'    => [
                    'cookies' => ['access' => 'abeon_token', 'refresh' => 'abeon_refresh'],
                ],
            ],
        ]));
    }

    private function user(string $id, ?int $orgId = null): User
    {
        return new User(
            id: $id,
            email: 'a@b.c',
            name: null,
            roles: [],
            permissions: [],
            orgId: $orgId,
        );
    }
}
