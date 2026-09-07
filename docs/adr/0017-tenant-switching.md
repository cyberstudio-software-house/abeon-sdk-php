# ADR-0017: Tenant switching by token re-issue

**Status:** Accepted
**Date:** 2026-08-12

## Context

ADR-0016 makes a user able to belong to more than one organisation, with roles held **per membership**.
The chrome therefore needs a tenant switcher, and something has to decide which organisation a given
request belongs to.

**Nothing in the platform does this today.** A search for `switch-tenant`, `switchTenant`,
`currentTenant` and `current_tenant` across `abeon-sdk-php/src`, `abeon-sdk-ts/src` and the dev
`abeon-auth-stub` returns **zero hits**, and no prior ADR covers it. The React MVP kept
`currentTenantId` in `localStorage` and re-fetched `tenant_apps` on change — which was safe there only
because tenancy was not an authorization dimension. Under ADR-0016 it is, so a client-side selection is
a client asserting its own authorization scope. That cannot be trusted.

Two designs were considered:

1. **Re-issue the token on switch.** Auth verifies membership and mints a new access token carrying the
   new `org_id`.
2. **Per-request tenant header.** The client sends e.g. `X-Abeon-Tenant`, and every service validates it
   against the user's memberships — either by a lookup or by a JWT claim listing every organisation the
   user belongs to.

## Decision

**Switching organisation re-issues the access token.**

### Endpoint

`POST /api/v1/auth/tenant` — owned by Auth, requires a valid user JWT.

**Request:** `{ "org_id": <integer> }`

**Behaviour:**
1. Verify the authenticated user has an **active membership** in the requested organisation. If not,
   respond `403` with a Problem Details body (ADR-0004). Do not distinguish "no such organisation" from
   "not a member" — that difference leaks the existence of other clients.
2. Mint a new access token with the new `org_id` **and the roles and permissions that apply to that
   membership**, since ADR-0016 holds them per membership.
3. Rotate the cookies exactly as `POST /api/v1/auth/refresh` does (ADR-0001 cookie names), so the
   browser path is identical to a refresh.

**Response:** the standard auth envelope, plus the `User` DTO for the new context, so the chrome can
update `useAuth()` without a second round-trip.

### Listing memberships

**`GET /api/v1/auth/tenants`** — a separate endpoint, returning `Tenant[]` (`schemas/dto/tenant.json`)
for the organisations the caller belongs to, with `current: true` on the active one.

Settled 2026-08-12 in favour of a separate endpoint rather than widening `GET /api/v1/auth/user`:

- The `User` DTO is contract-frozen, mirrored in `@abeon/sdk-ts` and covered by golden fixtures shared
  byte-for-byte between the two packages. Adding a nested collection to it is a breaking change to a
  working contract, for data with a different cardinality and a different refresh cadence.
- The switcher needs the list once per session; the user object is read on every chrome render.

**`Tenant` carries no roles or permissions**, deliberately. It is a display list. A client that could
read its own authorisation from it would be deriving authorisation from a response it can influence —
authorisation arrives only in the re-issued JWT.

### Consequences for services

**None.** Every service continues to trust the JWT alone. There is no new header to validate, no
membership lookup on the hot path, and no second source of truth for "which tenant is this". That is
the entire point of choosing this option.

## Consequences

**Positive:**
- One validation path. `AuthMiddleware` + `JwtValidator` already do the work; sixteen services need no
  new code and cannot get tenant validation subtly wrong in fifteen different ways.
- Permissions are correct by construction after a switch, because they are re-minted from the target
  membership rather than carried over from the previous one.
- A stolen or replayed token is scoped to one organisation, not to every organisation its subject
  belongs to.

**Negative / accepted:**
- Switching costs a round-trip and a token rotation. This is a deliberate, user-initiated action, so
  the latency is acceptable and the rotation is a feature, not a cost.
- Any long-lived work started before a switch (an open WebSocket, an in-flight upload, a queued job)
  still carries the previous `org_id`. The chrome must re-derive state on switch — apps
  (`useApps`), preferences (ADR-0009) and the Reverb subscription (ADR-0008, whose `private-org.{id}`
  guard already keys on the claim).
- Rejected: **per-request tenant header.** It avoids the round-trip, but pushes membership validation
  into every service — either an extra lookup on every request, or a JWT claim listing all of a user's
  organisations, which grows without bound and still requires each service to intersect it correctly.
  It converts a solved problem (trust the JWT) into sixteen unsolved ones.

## References

- Depends on: ADR-0016 (multi-tenant organisations)
- Related: ADR-0001 (JWT format, cookie names), ADR-0004 (Problem Details), ADR-0008 (broadcasting auth
  — `private-org.{id}`), ADR-0009 (preferences are per-tenant), ADR-0010 (`/auth/user`, `/auth/apps`)
- Implementation delta: `abeon-sdk-delta-2026-08-12.md` items 13–15 (`abeon-sdk-ts` tenant state and
  switch flow — the piece that exists nowhere today)
- MVP precedent (and why it is not sufficient): `unified-shell-spec.md` FR-2
