# ADR-0026: Administration — API and hooks in the SDK, screens in the boilerplate

**Status:** Accepted
**Date:** 2026-08-13

## Context

Auth needs an administration surface: users, memberships, invitations, roles, the permission catalog,
the audit view. Three shapes were available.

1. **Inertia inside `abeon-auth`.** Simplest to build. It also gives the identity service a frontend
   build — the one thing that would have to be rebuilt when the company-wide Auth eventually replaces
   this service.
2. **A separate administration application.** Keeps Auth an API, costs one more service to deploy,
   watch and keep in step with the chrome.
3. **Split it:** the API and its data-access layer ship as platform packages, and the screens ship in
   the boilerplate that every application starts from.

Option 3 is not a new pattern — it is exactly how the App Store surface already works, and that
precedent is the argument:

| Layer | Where it lives today (Store, ADR-0015) |
|---|---|
| HTTP endpoints | Auth serves `/api/v1/auth/store` |
| Data access | `useStore()` in `@abeon/shared` |
| Screen | `resources/js/Pages/Store.tsx` in `abeon-boilerplate-inertia`, routed at `/store` |

The Store is an administrative surface in every meaningful sense: it is gated on `core.apps.manage`, it
is org-scoped, and it changes entitlement. Its UI is a boilerplate page, not a packaged component.

## Decision

**`abeon-auth` ships no frontend. Administration splits along the Store's seam.**

### In the SDK — the backend half only

- **PHP:** base controllers under `Abeon\SDK\Auth\Endpoints\` for `/api/v1/auth/admin/*`, which
  `abeon-auth` mounts and extends. Same pattern as `UserController`, `PreferencesController` and
  `AppsController` today.
- **TypeScript:** data hooks in `@abeon/shared` — the administrative sibling of `useStore()` and
  `useApps()`. Fetching, caching, error shape and correlation-id forwarding live here, once.
- **No UI components.** `abeon-ui` and `@abeon/shared` do **not** grow admin screens, tables or forms.

### In the boilerplate — the screens

Administration pages live in `abeon-boilerplate-inertia/resources/js/Pages/`, next to `Store.tsx`,
`Settings.tsx` and `Profile.tsx`, routed like them. An application that needs administration starts from
the boilerplate and has it; an application that does not, deletes the page.

### In Auth — authorization, unchanged

`core.users.manage`, `core.roles.manage`, `core.apps.manage`, enforced server-side. Endpoints take no
organisation parameter; the caller's `org_id` comes from the token (ADR-0016). A screen renders what the
API allows and gates its controls on the token's grants (ADR-0024 and the ADR-0010 precedence rule) — a
frontend that decided its own permissions would be deriving authorization from the client.

### The consequence that makes this a decision rather than a preference

**`/api/v1/auth/admin/*` is a shared contract, because `@abeon/shared` consumes it.** Even with the
screens copied per application, the hooks are a package that every application depends on. So the
endpoints carry the same obligations as the chrome data plane:

- **Versioned** under `/api/v1/`, additive change only; a breaking change is an ADR.
- **Covered by golden fixtures** shared byte-for-byte between `abeon-sdk-php/schemas/fixtures/`
  and `abeon-shared/tests/contract/`, as ADR-0010's endpoints are.
- **Part of the swap-out set** (`abeon-auth-spec.md` NFR-10). A replacement Auth must serve
  administration too, or the hooks and screens are rewritten against whatever it exposes.

## Consequences

**Positive:**

- Auth stays a pure API service, which is the property that makes replacing it later a deployment
  change rather than a platform rewrite.
- No extra service to deploy, and no second place where the chrome must be kept consistent.
- The split already exists and is proven by the Store, so there is one pattern for platform surfaces
  rather than two competing ones.
- The packages keep what benefits from being shared — the wire contract, fetching, error handling — and
  the screens stay where product teams can change them without a package release.

**Negative / accepted:**

- **Screens are copied, not distributed.** The boilerplate is a template, so a fix to the role editor
  reaches only applications scaffolded afterwards. Existing applications drift, and there is no upgrade
  path short of a manual port. This is the central cost of the chosen shape, and it is the same cost the
  Store page already carries.
- **The administration API is a multi-consumer contract**, so every change needs fixtures on both sides
  — materially more expensive per change than an internal API for one panel.
- **The swap-out set grows** from roughly ten endpoints to ten plus administration.
- **Two repositories move together** for one feature: an endpoint in `abeon-sdk-php`, a hook in
  `abeon-shared`, a page in `abeon-boilerplate-inertia`. Worth watching — if administration keeps
  growing, a dedicated application (option 2) becomes right again, and this ADR should be superseded
  rather than stretched.

## References

- Source: `abeon-auth-spec.md` FR-28, FR-29 · `abeon-auth-plan.md` §A7, Decision 8
- Precedent, layer for layer: [ADR-0015](0015-app-store-and-entitlement.md) — `/api/v1/auth/store`,
  `useStore()`, `abeon-boilerplate-inertia/resources/js/Pages/Store.tsx` (routed in `routes/web.php`)
- Follows: [ADR-0010](0010-auth-me-and-apps-endpoints.md) (SDK base controllers extended by Auth),
  [ADR-0013](0013-full-page-navigation.md) (no shell application)
- Constrains: [ADR-0024](0024-permission-expansion.md) (screens gate on token grants),
  [ADR-0016](0016-multi-tenant-organisations.md) (org scope comes from the token, never a parameter)
