# ADR-0010: `/api/v1/auth/user` and `/api/v1/auth/apps` (chrome data plane)

**Status:** Accepted
**Date:** 2026-05-15

## Context

The federated chrome needs two read-only endpoints from the Auth service:

1. **Current user** — `<UserMenu>`, `<AbeonProvider initialAuth>` and `useAuth()` rely on a single source of truth for the active user's identity, roles, and permissions. While the JWT already carries these claims (ADR-0001), the chrome benefits from a server endpoint that re-derives them (single point of truth, future-proof for richer fields).
2. **Visible apps** — `<AppSwitcher>` and `useApps()` need the list of apps the current user can access, with display metadata (`label`, `path`, `icon`).

Both endpoints are referenced throughout `@abeon/shared` (`getServerAuthContext`, `useApps`, `useNotifications`) and the arch doc (§3.6), but until now had no formal schema.

## Decision

**Auth service exposes two endpoints with schemas defined here.** The SDK provides base controllers that Auth service extends. Other services never expose these — they call them through `ServiceClient::service('auth')` if needed.

### `GET /api/v1/auth/user`

Returns the current user. Requires user JWT.

**Response** (per ADR-0004 envelope):

```json
{
  "data": {
    "id": "42",
    "email": "alice@example.com",
    "name": "Alice Cooper",
    "roles": ["admin"],
    "permissions": ["crm.contacts.read", "crm.contacts.write", "finance.invoices.read"],
    "org_id": null
  },
  "meta": {}
}
```

Schema: [`schemas/dto/user.json`](../../schemas/dto/user.json) (already exists, unchanged).

**Why a server endpoint when the JWT carries this?**

- The JWT is opaque to the browser frontend by design (httpOnly cookie). The frontend cannot decode it. SSR (`getServerAuthContext`) can, but client-side React after hydration needs an HTTP path.
- Permissions may evolve between JWT issue and refresh — the endpoint can return the *current* set. (JWT remains the authorisation source of truth for every other service; this endpoint is for chrome display.)
- Future extension: avatar URL, presence, locale — none of which belong in the JWT.

### `GET /api/v1/auth/apps`

Returns the list of `AppDescriptor` rows from `ServiceRegistry`, filtered by the current user's permissions. Requires user JWT.

**Response**:

```json
{
  "data": [
    { "name": "crm",     "label": "CRM",      "path": "/crm",     "icon": "users",     "version": "1.0.0", "permissions": ["crm.*"],     "category": "Sprzedaż i finanse", "order": 10, "mode": "app", "fullscreen": false },
    { "name": "finance", "label": "Finanse",  "path": "/finance", "icon": "wallet",    "version": "1.0.0", "permissions": ["finance.*"], "category": "Sprzedaż i finanse", "order": 20, "mode": "app", "fullscreen": false },
    { "name": "pm",      "label": "Projekty", "path": "/pm",      "icon": "briefcase", "version": "0.9.0", "permissions": ["pm.*"],      "category": "Praca i treści",     "order": 30, "mode": "app", "fullscreen": false },
    { "name": "cms",     "label": "Treści",   "path": "/cms",     "icon": "file-text", "version": "1.2.0", "permissions": ["cms.*"],     "category": "Praca i treści",     "order": 40, "mode": "app", "fullscreen": false }
  ],
  "meta": { "total": 4 }
}
```

Schema: [`schemas/dto/app-descriptor.json`](../../schemas/dto/app-descriptor.json).

### AppDescriptor chrome fields (`category`, `order`, `mode`, `fullscreen`)

The descriptor carries four optional chrome-presentation fields (all nullable):

| Field | Type | Purpose |
|---|---|---|
| `category` | string \| null | Free-form AppSwitcher group label. The mega-menu groups apps by this value. |
| `order` | integer \| null | Sort hint within the switcher / category (ascending). |
| `mode` | `"suite"` \| `"app"` \| null | Chrome UI mode the app prefers; `"suite"` maps to the `abeon-home` dashboard (ADR-0014). |
| `fullscreen` | boolean \| null | Render chrome-less / full-bleed (e.g. a DAM/media app). |

These are **display-only** and do **not** affect the filtering rule below — visibility keys off
`permissions` alone. `@abeon/ui`'s `<Topbar>`/`<AppSwitcher>` consume `category`/`order` to render the
grouped, ordered mega-menu; `mode`/`fullscreen` are consumed by `<AppShell>` (the latter as the chrome
matures).

### Filtering rule

An app is included in the response if **any** permission declared in its `AppDescriptor.permissions` array is satisfied by the user — either by exact match, or by a wildcard `app.*` matching any of the user's `{app}.*` permissions. Pseudocode:

```php
$visible = collect($registry->list())
    ->filter(function (AppDescriptor $app) use ($user) {
        foreach ($app->permissions as $declared) {
            $prefix = strtok($declared, '.');   // e.g. "crm" from "crm.contacts.read" or "crm.*"
            foreach ($user->permissions as $granted) {
                if (str_starts_with($granted, $prefix . '.')) {
                    return true;
                }
            }
        }
        return false;
    })
    ->values();
```

This is intentionally permissive: a user with **any** permission on an app sees it in the switcher. Inside the app, finer-grained authorisation still uses the full permission string.

### Base controllers (provided by SDK)

`src/Auth/Endpoints/UserController.php` (new in Sprint S1) — invokable controller that reads `abeon_user()` and returns `ApiResponse::success($user)`. Auth service registers the route; the controller works on any service if anyone needs to expose it (most don't).

`src/Auth/Endpoints/AppsController.php` (new in Sprint S1) — invokable controller that combines `ServiceRegistry::list()` with `abeon_user()->permissions` per the filtering rule above. Auth service registers the route.

Both controllers require `AuthMiddleware`. Both return JSON in the ADR-0004 envelope.

### Caching

- `/api/v1/auth/user`: no server cache (cheap, JWT-derived).
- `/api/v1/auth/apps`: cached in Redis for 60 seconds, keyed by `user_id`. Invalidated when a service re-registers (`ServiceRegistry::register()`) — the registration event bumps a registry-version cache key, and the apps endpoint includes that version in its cache key.

### Empty / degraded responses

- A user with no permissions: `/auth/apps` returns `{ data: [], meta: { total: 0 } }`. Chrome renders an empty switcher with a "Skontaktuj się z administratorem" message.
- Auth service unreachable: chrome falls back to its in-memory cache (per session) and shows a `<StatusBadge variant="warning">` in the topbar.

## Consequences

**Positive:**

- Two stable endpoints unlock every chrome component (`<UserMenu>`, `<AppSwitcher>`, `useAuth`, `useApps`).
- Permission-prefix filtering is simple, predictable, and decoupled from each app's internal authorisation — apps still own their fine-grained checks.
- 60s Redis cache on `/auth/apps` keeps Auth latency low even when 16 chromes poll concurrently.

**Negative / accepted:**

- `permissions` array can grow large for power users. Chrome's `useAuth().hasPermission()` is O(n) lookup — acceptable for n ≤ 200; if it grows much beyond that, we'll add a `Set` index inside `useAuth()`.
- The prefix-match rule means an app with NO declared permissions is invisible to everyone. Apps must declare at least one permission entry (even a sentinel `crm.access`) — documented in the boilerplates.
- `/auth/user` duplicates JWT claims. Acceptable cost; the JWT remains the source of truth for authorisation, the endpoint is for display only.

## References

- Schemas: `schemas/dto/user.json`, `schemas/dto/app-descriptor.json`
- Implementation: `src/Auth/Endpoints/UserController.php`, `src/Auth/Endpoints/AppsController.php` (Sprint S1)
- Chrome consumers: `abeon-shared/src/react/use-auth.ts`, `use-apps.ts`, `_internal/`
- Related: ADR-0001 (JWT), ADR-0004 (REST envelope), ADR-0009 (preferences alongside)
- Arch doc: §3.6 (App Registry)
