# ADR-0001: JWT format (user + service)

**Status:** Accepted
**Date:** 2026-05-14

## Context

The Abeon Unified platform spans 16+ microservices. Cross-service authentication must work for two distinct subjects:

1. **End users** carrying short-lived access tokens issued by the Auth service.
2. **Services calling other services** carrying short-lived tokens they sign themselves.

Without an explicit specification, every service would invent its own JWT payload, causing silent drift. Every validator would have to maintain a private mapping. Replays and confusion attacks become easy when claims like `iss`/`aud` are loose.

## Decision

Both token types use **RS256** signed JWTs. Public keys are served from the Auth service's JWKS endpoint at `${auth.url}/.well-known/jwks.json`. Each signing key is uniquely identified by `kid` in the JWT header; JWKS may return multiple keys to support zero-downtime rotation.

### User token claims

Canonical schema: [`schemas/auth/jwt-user.json`](../../schemas/auth/jwt-user.json).

| Claim | Required | Notes |
|---|---|---|
| `sub` | yes | Stable user ID (string). |
| `exp` | yes | Unix timestamp. **15 min** default lifetime. |
| `iat` | yes | Unix timestamp. |
| `iss` | yes | Constant `"abeon-auth"`. |
| `aud` | yes | Constant `"abeon"`. |
| `type` | yes | Constant `"user"`. |
| `email` | yes | RFC 5322 email. |
| `name` | optional | Display name. |
| `roles` | yes | List of role names. |
| `permissions` | yes | List of `{app}.{resource}.{action}` strings (e.g. `crm.contacts.read`). Regex `^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$`. **Literal grants only — no wildcards.** Auth expands roles to fully-qualified permissions at issue time, subject to a token-size budget (ADR-0024). |
| `org_id` | **yes** | Integer organization ID. **The organisation the token is scoped to** — an authorization and data-scoping dimension, not a display field (ADR-0016). A user belonging to several organisations holds a token for exactly one at a time; switching re-issues the token (ADR-0017). |
| `jti` | yes | Unique token ID — enables revocation lists. **Mechanism specified in ADR-0023:** v1 revokes the refresh family and lets the access token expire (≤ 15 min); v2 pushes `auth.token.revoked` to a per-service local deny-list. |

### Service token claims

Canonical schema: [`schemas/auth/jwt-service.json`](../../schemas/auth/jwt-service.json).

| Claim | Required | Notes |
|---|---|---|
| `sub` | yes | Service identifier (same as `service_name`). |
| `exp` | yes | Unix timestamp. **5 min** default lifetime. |
| `iat` | yes | Unix timestamp. |
| `iss` | yes | Issuing service name (e.g. `"crm"`). |
| `aud` | yes | Constant `"abeon"`. |
| `type` | yes | Constant `"service"`. |
| `service_name` | yes | Calling service identifier. |
| `org_id` | optional | Present when the service acts **on behalf of an organisation** (ADR-0016). Absent for genuinely organisation-less work — scheduled maintenance, registry self-registration, health probes. See ADR-0005. |
| `jti` | yes | Unique token ID. |

### Validation rules

Every service validates inbound JWTs **locally** using the cached JWKS (no Auth round-trip per request):

1. Header `kid` must match a JWKS key; otherwise flush cache once and retry.
2. Signature verified via RS256 against the resolved JWK.
3. `iss` must equal the configured issuer (`abeon-auth` for user tokens; emitting service name for service tokens — validator inspects `type` to know which to expect).
4. `aud` must equal `abeon`.
5. `exp` not in the past, **allowing 60 seconds of clock skew** (`JWT::$leeway = 60`, set centrally by
   the SDK). Nodes drift independently; a validator with no tolerance rejects tokens that were just
   minted.
6. `type` matches the expected token kind for the endpoint.

Implementation: `Abeon\SDK\Auth\JwtValidator` + `Abeon\SDK\Auth\JwksClient`.

### Key rotation

- Each service holds its private key as a Kubernetes Secret mounted as `ABEON_SERVICE_JWT_PRIVATE_KEY` and a `kid` as `ABEON_SERVICE_JWT_KID`.
- Public keys for all services are aggregated into a single JWKS document served by Auth. Two strategies (current and future):
  - **Current:** shared ConfigMap with all public keys, mounted into Auth pod, exposed at JWKS endpoint.
  - **Future (M7):** K8s label discovery — Auth scrapes services labeled `abeon.io/service=true`, reads `abeon.io/jwks-url` annotation, builds JWKS dynamically.
- Rotation: deploy service with new `(kid, private_key)` pair; old `kid` removed from JWKS after grace period.

### Cookie ↔ Authorization translation (frontend boilerplate)

The Auth service issues the access token as an httpOnly cookie on `.abeon.pl` (cookie name from `auth.cookies.access`, default `abeon_token`). Frontend backends (Laravel + Inertia or Next.js SSR) **must read this cookie and attach the JWT as `Authorization: Bearer <value>`** before calling downstream services. `Abeon\SDK\Auth\AuthMiddleware` only reads the `Authorization` header — it does **not** inspect cookies. See [`@abeon/sdk-ts`](../../../abeon-sdk-ts/) for the TypeScript helper `createServerApiClient(cookies, headers)` that performs this translation.

> **Amended 2026-08-13: Auth performs the translation for itself.** The rule above covers services a
> frontend backend calls *on the user's behalf*. Auth is also reachable **directly from a browser** —
> architecture doc §7.1 routes `app.abeon.pl/auth/*` straight to it — and such a request carries the
> cookie and no header. Auth therefore mounts its own cookie→Bearer bridge ahead of `AuthMiddleware`
> (`abeon-auth-spec.md` FR-39). An explicit `Authorization` header always wins, so a service-to-service
> call is never overridden by a browser cookie riding along.
>
> Note this leaves an open routing question rather than settling it: the chrome calls
> `/api/v1/auth/*`, which §7.1 maps to *"wewnętrzne API (service-to-service, opcjonalnie)"* rather than
> to Auth. See `abeon-auth-spec.md` finding A-21 — the bridge makes Auth correct under either answer.

## Consequences

**Positive:**
- Local validation = constant-time per request; Auth is not a hot path.
- JWKS multi-`kid` enables zero-downtime rotation.
- Permission name regex catches typos at JWT-issue time and at consumer validation.
- Type claim distinguishes user vs service in audit logs and policy decisions.

**Negative / accepted:**
- 15-minute user token requires refresh token flow (separate refresh cookie). Mitigated by `@abeon/sdk-ts` `refreshTokenIfExpired()` helper. **Refresh tokens rotate and reuse kills the family — ADR-0023.**
- Compromised service private key has 5-minute exposure until JWKS rotation propagates.
- Permission catalog is federated (each service declares its own via `service.permissions.declared` event) — no central RBAC table. Single source of truth requires aggregation in Auth.

## References

- Implementation: `src/Auth/JwtValidator.php`, `src/Auth/JwksClient.php`, `src/Auth/AuthMiddleware.php`
- Issuer: `src/Client/ServiceTokenProvider.php` (service tokens; user tokens issued by Auth service)
- Schemas: `schemas/auth/jwt-user.json`, `schemas/auth/jwt-service.json`
- Related: ADR-0005 (service-to-service auth flow), arch doc sekcja 5
- **Amended 2026-08-12 by ADR-0016** (multi-tenant organisations): `org_id` became **required** on user
  tokens and is now an authorization dimension — it was previously *"optional … for multi-org
  accounts"*, informational only under the superseded ADR-0012. The service token gained an optional
  `org_id` for on-behalf-of calls. See also ADR-0017 (switching re-issues the token).
- **Amended 2026-08-13 by [ADR-0023](0023-token-revocation-and-sessions.md)** (revocation and sessions):
  `jti`'s "enables revocation lists" gained an actual mechanism, and the refresh flow this ADR introduced
  gained defined semantics — rotation on every use, family-wide revocation on reuse, and a stated
  ≤ 15-minute window before a revoked session's access token stops working.
- **Amended 2026-08-13 by [ADR-0024](0024-permission-expansion.md)** (permission expansion): the
  `permissions` claim is literal grants only, expanded from roles by Auth at issue time, with a 4 KB
  token-size budget enforced by test. Role-only tokens are the named escape hatch if that budget is ever
  breached — which would make `permissions` optional here.
- **Amended 2026-08-13 by [ADR-0025](0025-auth-service-invariants.md)** (Auth service invariants):
  validation rule 5 gained **60 seconds of clock-skew tolerance**. The tolerance had never been stated,
  and `JWT::$leeway` was therefore 0 across the platform — a validator one second ahead of the issuer
  rejects a freshly minted token. ADR-0025 also fixes what happens when expansion would breach the
  ADR-0024 budget: Auth refuses to mint rather than truncating the claim.
- **Amended 2026-08-13: Auth bridges cookie → `Authorization` for itself.** See the note in *Cookie ↔
  Authorization translation* above. The original rule assigned the translation to frontend backends,
  which does not cover a browser reaching Auth directly. Requirement: `abeon-auth-spec.md` FR-39; the
  unresolved ingress question it exposes: finding A-21.
- **Impersonation, decided 2026-08-13, implementation deferred.** When impersonation ships
  (`abeon-auth-spec.md` FR-30), the real actor is carried by an **`act` claim in the style of RFC 8693**
  — an impersonated token must be distinguishable from an ordinary one in every service and in the audit
  log. The claim table above gains `act` at that point, along with the `User` DTO in both SDKs; it is
  recorded here now so the shape is not invented under deadline later.
