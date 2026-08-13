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

An app is included in the response if it is **assigned to the caller's organisation** (`AppDescriptor.enabled === true`, meaning a `tenant_apps` row exists for `org_id` — ADR-0015 as amended by ADR-0016) **AND** the user holds a matching permission — i.e. **any** permission declared in its `AppDescriptor.permissions` array is satisfied, either by exact match or by a wildcard `app.*` matching any of the user's `{app}.*` permissions. Pseudocode:

```php
$visible = collect($registry->listForOrg($user->orgId))
    ->filter(function (AppDescriptor $app) use ($user) {
        if ($app->enabled !== true) {
            return false;   // this organisation does not have the app (ADR-0015 / ADR-0016)
        }
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

So visibility is **`tenant_apps` ∩ permissions** — the app is assigned to the caller's organisation *and* the user is permitted. This is the rule the React MVP had (*"available apps = `tenant_apps(currentTenant)` intersected with the user's role permissions"*), restored by ADR-0016. Assignment is managed via the store API (ADR-0015: `GET /api/v1/auth/store`, `POST /api/v1/auth/store/{app}/{enable|disable}`), which now acts on the caller's organisation; the permission half stays permissive (any permission on an app surfaces it). Inside the app, finer-grained authorisation still uses the full permission string.

> **Implementation note (2026-08-12).** The SDK's base `AppsController` can only enforce part of this
> rule, and the split is structural rather than an oversight:
>
> - The **permission half** is enforced in full.
> - The **assignment half** is enforced only negatively — an app explicitly `enabled === false` is
>   hidden. It cannot be enforced positively there, because `ServiceRegistry::list()` returns
>   descriptors as services self-registered them, and self-registration leaves `enabled` null *by this
>   ADR's own contract* (ADR-0015 §1: "null/ignored on self-registration"). Treating null as a denial
>   would render every self-registered catalogue empty.
>
> Resolving `enabled` for an organisation requires the `tenant_apps` relation, which lives in the
> registry's owning service (AbeonUnified, ADR-0019). **An extending controller with access to that
> relation MUST resolve `enabled` per organisation before filtering** — which is why Auth owns this
> endpoint canonically and may bypass `ServiceRegistry::list()` to read the table directly.
>
> Until 2026-08-12 the base controller ignored `enabled` entirely, so an explicitly disabled app stayed
> visible to anyone using it directly; only `abeon-auth-stub`'s subclass applied enablement.

### Ownership: the registry moves to AbeonUnified (ADR-0019)

The app registry — the catalogue and the organisation↔app assignment behind it — is owned by
**AbeonUnified**, not Auth. Auth owns users, memberships, roles and permissions.

**Paths do not change.** `GET /api/v1/auth/apps` and the store endpoints stay where they are, and Auth
**proxies** to Unified for the catalogue half. Chosen over moving the paths because:

- The chrome, both SDKs and the boilerplate already call `/api/v1/auth/apps`; moving it would be a
  breaking change to a working contract for no user-visible gain.
- The response needs the caller's permissions *and* the organisation's app assignment. Auth already
  holds the first and has the authenticated context; proxying keeps one round-trip from the browser.

Self-registration **does** move: `ServiceRegistry::register()` targets `service('unified')` instead of
`service('auth')`, since nothing depends on that path but the SDK itself.

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
- **Amended 2026-06-26 by ADR-0015**: filtering rule became org-enabled AND permission (was
  permission-only).
- **Amended 2026-08-12 by ADR-0016 and ADR-0019**: the rule is now **`tenant_apps` ∩ permissions**, the
  registry is owned by AbeonUnified with Auth proxying the read paths, and self-registration targets
  `service('unified')`.

  > **Corrected 2026-08-13.** This note originally continued: *"`GET /api/v1/auth/user` also gains the
  > caller's organisation memberships, so the tenant switcher (ADR-0017) has something to render."*
  > That never happened and was never the decision. **ADR-0017, written the same day, explicitly chose a
  > separate `GET /api/v1/auth/tenants` endpoint** rather than widening this one, precisely because the
  > `User` DTO is contract-frozen and mirrored in golden fixtures on both sides. The code agrees with
  > ADR-0017: `schemas/dto/user.json`, `src/DTO/User.php` and `abeon-shared/src/types/user.ts` carry no
  > membership collection (verified 2026-08-13). The `User` DTO is unchanged by ADR-0016.

- **Amended 2026-08-13 by [ADR-0025](0025-auth-service-invariants.md)** (Auth service invariants):
  `GET /api/v1/auth/apps` may cache per `org_id` for at most 60 seconds and **must** invalidate on any
  store write, so an entitlement change is visible on the next request rather than after a TTL. An
  organisation with no assigned applications returns an empty list with 200, never an error — the state
  is reachable between provisioning and Unified's assignment landing (ADR-0022).

- **Amended 2026-08-13: which permission set gates the UI.** This ADR justified the endpoint partly on
  the grounds that *"permissions may evolve between JWT issue and refresh — the endpoint can return the
  current set"*, while ADR-0024 makes the token the source of authorization. Both cannot govern what the
  chrome renders. **The token wins:** the chrome gates controls on the grants in the caller's token, so a
  user is never shown a control that every service will refuse. The endpoint MAY additionally expose a
  freshly re-derived set in a separate field for administrative views, but that field must not drive
  gating. The cost is accepted and bounded: a permission *granted* mid-session becomes visible only after
  the next token issue, within the 15-minute window ADR-0023 defines.

- **Amended 2026-08-13 by [ADR-0026](0026-administration-is-an-sdk-surface.md)**: the administration
  endpoints `/api/v1/auth/admin/*` join this ADR's endpoints as a shared, versioned, fixture-covered
  contract, because `@abeon/shared` consumes them from every application.
