<?php

declare(strict_types=1);

namespace Abeon\SDK\Broadcasting;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reusable `/broadcasting/auth` controller for Reverb / Laravel Echo.
 *
 * Per ADR-0008: every service mounting the chrome exposes this endpoint
 * so the chrome's `createEcho()` factory can authorise channel subscriptions.
 *
 * Flow:
 *   1. Read the `abeon_token` httpOnly cookie (name from `abeon.auth.cookies.access`).
 *   2. Decode the JWT via the standard `JwtValidator`.
 *   3. Push the User into `AuthContext` AND `setUserResolver()` so any
 *      `Broadcast::channel(...)` callback registered in the service's
 *      `routes/channels.php` resolves `$user` correctly.
 *   4. Apply the SDK default policy for `user.{id}` (and `org.{id}` if claim
 *      present), then delegate signing to Laravel's `Broadcaster::auth()`
 *      driver, which falls back to service-specific channel policies.
 *
 * Mount in the service's routes:
 *
 *     Route::post('/broadcasting/auth', \Abeon\SDK\Broadcasting\BroadcastingAuthController::class)
 *         ->middleware('web');   // web group so cookie + CSRF middleware run
 *
 * Do NOT add `abeon.auth` middleware — Echo's client sends the cookie, not the
 * Authorization header, so AuthMiddleware would reject the request.
 */
class BroadcastingAuthController
{
    public function __construct(
        private readonly JwtValidator $jwt,
        private readonly AuthContext $authContext,
        private readonly AbeonConfig $config,
        private readonly Broadcaster $broadcaster,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->cookie($this->config->authAccessCookieName());
        if (! is_string($token) || $token === '') {
            throw AuthException::unauthenticated('Missing access cookie');
        }

        $user = $this->jwt->decodeUser($token);
        $this->authContext->set($user);
        $request->setUserResolver(fn () => $user);

        $channel = (string) $request->input('channel_name', '');

        if (! $this->canSubscribe($user, $channel)) {
            throw AuthException::forbidden('Channel subscription not allowed');
        }

        // Delegate signing to Laravel's broadcaster (Reverb/Pusher driver).
        $response = $this->broadcaster->auth($request);

        if ($response instanceof JsonResponse) {
            return $response;
        }
        if (is_string($response)) {
            $decoded = json_decode($response, true);

            return new JsonResponse(is_array($decoded) ? $decoded : ['auth' => $response]);
        }

        return new JsonResponse($response);
    }

    /**
     * SDK default policy for the channels the chrome owns:
     *
     *   - `private-user.{id}`  → token's `sub` must equal `{id}`
     *   - `private-org.{id}`   → token's `org_id` must equal `{id}` (when present)
     *
     * Anything else returns `true` here and falls through to the service's
     * `Broadcast::channel(...)` policies via the Broadcaster driver.
     */
    private function canSubscribe(User $user, string $channel): bool
    {
        if (preg_match('/^private-user\.(.+)$/', $channel, $m) === 1) {
            return (string) $user->id === $m[1];
        }

        if (preg_match('/^private-org\.(.+)$/', $channel, $m) === 1) {
            return $user->orgId !== null && (string) $user->orgId === $m[1];
        }

        return true;
    }
}
