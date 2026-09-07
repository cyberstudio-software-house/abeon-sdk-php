# ADR-0008: Broadcasting authorisation (`/broadcasting/auth`)

**Status:** Accepted
**Date:** 2026-05-15

## Context

The chrome's `useNotifications()` hook + `createEcho()` factory (in `@abeon/sdk-ts`) require every service mounting the chrome to expose a `/broadcasting/auth` endpoint that Laravel Echo can call before subscribing to private/presence channels.

The Reverb broadcaster sends a POST to `/broadcasting/auth` with `socket_id` and `channel_name`; the response must be a string token. The conventional Laravel `BroadcastController` reads `auth()->user()` — but Abeon services authenticate via JWT in the `Authorization` header (per ADR-0001), and Echo's auth call carries no header by default. The `abeon_token` cookie (`.abeon.pl`) **is** sent.

Without a SDK helper, every one of the 16 services would hand-roll the same cookie-to-JWT-to-token bridge. ADR-0001 line 76 already states "frontend backends must read the cookie and attach the JWT as Bearer" — that is the **client-side** translation. The `/broadcasting/auth` endpoint is the **server-side** equivalent for WebSocket auth and needs the same logic.

## Decision

The SDK provides a **reusable broadcasting auth controller** that every service mounts on its own `/broadcasting/auth` route. Implementations may extend it for custom channel authorisation; the default behaviour covers all chrome-related channels.

### Implementation

`Abeon\SDK\Broadcasting\BroadcastingAuthController` — invokable controller. Pseudocode:

```php
final class BroadcastingAuthController
{
    public function __construct(
        private JwtValidator $jwt,
        private BroadcastAuthorizer $reverb,
        private AbeonConfig $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $cookieName = $this->config->cookies()->access;     // default abeon_token
        $token = $request->cookie($cookieName);
        if (! $token) {
            throw AuthException::unauthenticated();
        }

        $user = $this->jwt->decodeUser($token);             // validates iss/aud/exp/sig
        AuthContext::set($user);                            // so channel callbacks see abeon_user()

        $channel = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        if (! $this->canSubscribe($user, $channel)) {
            throw AuthException::forbidden();
        }

        // Returns the structure Reverb / pusher-js expects.
        return new JsonResponse($this->reverb->authorize($user, $channel, $socketId));
    }

    private function canSubscribe(User $user, string $channel): bool
    {
        // Default policy: `user.{id}` is private to that user; anything else delegates to Laravel's Broadcast::channel() routes.
        if (preg_match('/^private-user\.(.+)$/', $channel, $m)) {
            return (string) $user->id === $m[1];
        }
        return Broadcast::auth($request)->status() === 200;  // standard Laravel fallback
    }
}
```

Route registration in `AbeonServiceProvider`:

```php
Route::post('/broadcasting/auth', BroadcastingAuthController::class)
    ->middleware(['web']);   // web group so the cookie is parsed; NOT AbeonAuth middleware
```

The controller does **not** use `AuthMiddleware` because that middleware reads the `Authorization` header, which Echo does not send. The controller reads the cookie directly.

### Channel naming conventions

| Channel | Audience | Authorisation |
|---|---|---|
| `private-user.{id}` | Single user. | Token's `sub` must equal `{id}`. SDK default policy. |
| `private-org.{id}` | All users of an org. | Token's `org_id` must equal `{id}`. SDK default policy if `org_id` claim present. |
| Anything else (e.g. `private-crm.deal.{id}`) | Service-specific. | Service registers its own `Broadcast::channel(...)` policy — SDK falls through to Laravel's `Broadcast::auth()`. |

Services that need a custom channel pattern register it via Laravel's standard `routes/channels.php`. The SDK never owns business channels.

### XSRF / CSRF

`/broadcasting/auth` mounts under Laravel's `web` middleware group, which includes CSRF. Echo's pusher-js automatically sends `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie — this is handled by `@abeon/sdk-ts` `createEcho()` already.

### Configuration

No new config keys — the controller uses `config('abeon.auth.cookies.access')` (already defined in ADR-0001) and the existing Reverb config.

## Consequences

**Positive:**

- Every service mounts the same route with one line: `Route::post('/broadcasting/auth', \Abeon\SDK\Broadcasting\BroadcastingAuthController::class);` — no per-service crypto code.
- `AuthContext::set()` during the auth call means `Broadcast::channel(...)` callbacks can use `abeon_user()` for fine-grained authorisation, identical to HTTP request handlers.
- Cookie-to-JWT translation lives in one place across the platform — no drift.

**Negative / accepted:**

- Each service still owns its custom channel policies via `routes/channels.php`. SDK only handles the SDK-owned `user.*` and `org.*` channels by default.
- Echo must use **cookie auth** (credentials: 'include'), which means the WebSocket origin must be same-site or a subdomain of `.abeon.pl`. Reverb deployed under a separate hostname requires CORS + SameSite tuning (operational, not SDK).
- The controller bypasses `AuthMiddleware`. If a service replaces SDK auth wholesale, it must remember to wire `BroadcastingAuthController` separately — documented in the SDK README.

## References

- Implementation: `src/Broadcasting/BroadcastingAuthController.php` (Sprint S1)
- Used by chrome: `@abeon/sdk-ts` `createEcho({ authEndpoint })`, `useNotifications()`
- Related: ADR-0001 (JWT format & cookie translation), ADR-0006 (notifications channel `user.{id}`)
- Reverb docs: https://reverb.laravel.com/docs/1.x/authorizing-channels
